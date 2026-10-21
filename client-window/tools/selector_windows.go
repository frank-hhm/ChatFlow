//go:build windows

package tools

import (
	"fmt"
	"image"
	"log"
	"os"
	"runtime"
	"sync"
	"syscall"
	"time"
	"unsafe"

	"golang.org/x/sys/windows"
)

// ============================================================
// Windows GDI 屏幕选区工具（微信风格选区体验）
//
// 本次实现聚焦“与微信一致的区域选择”交互：
//   1. 全屏深色遮罩 + 拖框选区
//   2. 跟随光标的十字光标（IDC_CROSS）
//   3. 放大镜取色窗：像素级放大 + 中心红色十字准星 + 坐标 + 颜色值
//   4. 选区实时尺寸提示（宽 × 高）
//   5. 确认：双击选区内部 或 回车键
//   6. 取消：ESC 键 或 右键
//   7. 选区确定后仍可再次拖框重新选择（点击选区内部保留当前选区）
//
// 说明：标注工具栏、8 向缩放/移动控制点不在本次范围内。
// ============================================================

// ============================================================
// GDI 颜色常量
// ============================================================

// 选区边框、放大镜等直接写入 DIB，使用 r/g/b 分量表达
const (
	borderR, borderG, borderB = byte(255), byte(0), byte(0)     // 选区边框（红色）
	gridR, gridG, gridB       = byte(110), byte(110), byte(110) // 放大镜网格线
	crossR, crossG, crossB    = byte(255), byte(0), byte(0)     // 放大镜中心十字
)

// ============================================================
// 放大镜几何参数
// ============================================================

const (
	magSrc     = 15 // 放大镜采样的源像素边长（奇数，含中心像素）
	magScale   = 10 // 每个源像素放大倍数
	magGap     = 18 // 放大镜与光标的间距
	magFrame   = 2  // 外框厚度
	magLabelH  = 48 // 标签区高度
	magContent = magSrc * magScale
	magHalf    = magSrc / 2
	textColor  = 0x00FFFFFF // 文字白色（BGR）
	labelBG    = 0x00000000 // 标签底色（黑）
)

// ============================================================
// Windows API 常量
// ============================================================

const (
	// Window styles / class styles
	WS_EX_TOPMOST = 0x00000008
	WS_EX_LAYERED = 0x00080000
	WS_POPUP      = 0x80000000
	CS_OWNDC      = 0x00000020
	CS_DBLCLKS    = 0x00000008
	SW_SHOW       = 5

	// Cursor resources
	IDC_CROSS = 32515

	// System metrics
	SM_CXSCREEN = 0
	SM_CYSCREEN = 1

	// Window messages
	WM_MOUSEMOVE     = 0x0200
	WM_LBUTTONDOWN   = 0x0201
	WM_LBUTTONUP     = 0x0202
	WM_LBUTTONDBLCLK = 0x0203
	WM_RBUTTONUP     = 0x0205
	WM_KEYDOWN       = 0x0100
	WM_DESTROY       = 0x0002
	WM_PAINT         = 0x000F
	WM_ERASEBKGND    = 0x0014

	// Virtual keys
	VK_ESCAPE = 0x1B
	VK_RETURN = 0x0D

	// Raster operations
	SRCCOPY  = 0x00CC0020
	ulwAlpha = 0x00000002

	// Text
	TRANSPARENT = 1
	FW_NORMAL   = 400
)

// ============================================================
// user32.dll 过程句柄
// ============================================================

var (
	modUser32 = windows.NewLazySystemDLL("user32.dll")

	procCreateWindowExW            = modUser32.NewProc("CreateWindowExW")
	procShowWindow                 = modUser32.NewProc("ShowWindow")
	procUpdateWindow               = modUser32.NewProc("UpdateWindow")
	procGetMessageW                = modUser32.NewProc("GetMessageW")
	procTranslateMessage           = modUser32.NewProc("TranslateMessage")
	procDispatchMessageW           = modUser32.NewProc("DispatchMessageW")
	procDestroyWindow              = modUser32.NewProc("DestroyWindow")
	procDefWindowProcW             = modUser32.NewProc("DefWindowProcW")
	procSetCapture                 = modUser32.NewProc("SetCapture")
	procReleaseCapture             = modUser32.NewProc("ReleaseCapture")
	procPostQuitMessage            = modUser32.NewProc("PostQuitMessage")
	procGetSystemMetrics           = modUser32.NewProc("GetSystemMetrics")
	procGetDC                      = modUser32.NewProc("GetDC")
	procReleaseDC                  = modUser32.NewProc("ReleaseDC")
	procSetLayeredWindowAttributes = modUser32.NewProc("SetLayeredWindowAttributes")
	procUpdateLayeredWindow        = modUser32.NewProc("UpdateLayeredWindow")
	procRegisterClassW             = modUser32.NewProc("RegisterClassW")
	procUnregisterClassW           = modUser32.NewProc("UnregisterClassW")
	procLoadCursorW                = modUser32.NewProc("LoadCursorW")
	procSetForegroundWindow        = modUser32.NewProc("SetForegroundWindow")
	procSetFocus                   = modUser32.NewProc("SetFocus")
	procGetCursorPos               = modUser32.NewProc("GetCursorPos")
	procBeginPaint                 = modUser32.NewProc("BeginPaint")
	procEndPaint                   = modUser32.NewProc("EndPaint")
)

// ============================================================
// gdi32.dll / gdi32full.dll 过程句柄
// ============================================================

var (
	modGdi32 = windows.NewLazySystemDLL("gdi32.dll")

	procCreateCompatibleDC = modGdi32.NewProc("CreateCompatibleDC")
	procCreateDIBSection   = modGdi32.NewProc("CreateDIBSection")
	procSelectObject       = modGdi32.NewProc("SelectObject")
	procDeleteObject       = modGdi32.NewProc("DeleteObject")
	procDeleteDC           = modGdi32.NewProc("DeleteDC")
	procBitBlt             = modGdi32.NewProc("BitBlt")
	procGetPixel           = modGdi32.NewProc("GetPixel")
	procCreateFontW        = modGdi32.NewProc("CreateFontW")
	procTextOutW           = modGdi32.NewProc("TextOutW")
	procSetTextColor       = modGdi32.NewProc("SetTextColor")
	procSetBkMode          = modGdi32.NewProc("SetBkMode")
)

// ============================================================
// kernel32.dll 过程句柄
// ============================================================

var (
	modKernel32          = windows.NewLazySystemDLL("kernel32.dll")
	procGetModuleHandleW = modKernel32.NewProc("GetModuleHandleW")
)

// ============================================================
// Windows 结构体定义
// ============================================================

type POINT struct {
	X int32
	Y int32
}

type SIZE struct {
	CX int32
	CY int32
}

type BLENDFUNCTION struct {
	BlendOp             byte
	BlendFlags          byte
	SourceConstantAlpha byte
	AlphaFormat         byte
}

type RECT struct {
	Left   int32
	Top    int32
	Right  int32
	Bottom int32
}

type MSG struct {
	Hwnd    uintptr
	Message uint32
	WParam  uintptr
	LParam  uintptr
	Time    uint32
	Pt      POINT
}

type WNDCLASSW struct {
	Style         uint32
	LpfnWndProc   uintptr
	CbClsExtra    int32
	CbWndExtra    int32
	HInstance     uintptr
	HIcon         uintptr
	HCursor       uintptr
	HbrBackground uintptr
	LpszMenuName  *uint16
	LpszClassName *uint16
}

type BITMAPINFOHEADER struct {
	BiSize          uint32
	BiWidth         int32
	BiHeight        int32
	BiPlanes        uint16
	BiBitCount      uint16
	BiCompression   uint32
	BiSizeImage     uint32
	BiXPelsPerMeter int32
	BiYPelsPerMeter int32
	BiClrUsed       uint32
	BiClrImportant  uint32
}

type BITMAPINFO struct {
	BmiHeader BITMAPINFOHEADER
	BmiColors uintptr
}

// PAINTSTRUCT 用于 BeginPaint/EndPaint 配对处理 WM_PAINT。
type PAINTSTRUCT struct {
	HDC         uintptr
	FErase      int32
	Rc          RECT
	FRestore    int32
	FIncUpdate  int32
	RgbReserved [32]byte
}

// ============================================================
// 选区状态（线程安全）
// ============================================================

type selectorState struct {
	mu       sync.Mutex
	pressing bool  // 正在拖框
	hasSel   bool  // 已有确定的选区
	startX   int32 // 选区起点
	startY   int32
	endX     int32 // 选区终点
	endY     int32
	curX     int32 // 当前光标位置
	curY     int32
	done     bool // 已确认（或取消后退出）
	cancel   bool // 用户取消
}

// getRectLocked 归一化选区矩形（调用方必须已持有 mu）
func (st *selectorState) getRectLocked() image.Rectangle {
	x1, y1 := int(st.startX), int(st.startY)
	x2, y2 := int(st.endX), int(st.endY)
	if x1 > x2 {
		x1, x2 = x2, x1
	}
	if y1 > y2 {
		y1, y2 = y2, y1
	}
	return image.Rect(x1, y1, x2, y2)
}

// containsLocked 判断点是否落在当前选区内（调用方必须已持有 mu）
func (st *selectorState) containsLocked(x, y int32) bool {
	r := st.getRectLocked()
	return x >= int32(r.Min.X) && x < int32(r.Max.X) &&
		y >= int32(r.Min.Y) && y < int32(r.Max.Y)
}

// ============================================================
// 全局状态（窗口过程回调无法传参）
// ============================================================

var gState *selectorState

// ============================================================
// 说明：不再使用 GDI FillRect（其实际位于 user32.dll，且 LazyProc
// 指针恒非 nil、存在性判断不可靠会导致 Call 时 panic）。
// 遮罩改为对全屏 DIB 直接做变暗处理，见 dibDim / doRedraw。
// ============================================================

// ============================================================
// 日志辅助
//
//   - selLogf：关键步骤（生命周期 / 用户离散操作）始终输出。
//   - selDebugf：高频路径（鼠标移动、每帧重绘）仅当环境变量
//     CHATFLOW_SELECTOR_DEBUG=1 时输出，避免刷屏。
//     输出统一带 [selector] 前缀，便于在日志中过滤。
// ============================================================

var selDebugEnabled = os.Getenv("CHATFLOW_SELECTOR_DEBUG") == "1"

func selLogf(format string, args ...any) {
	log.Printf("[selector] "+format, args...)
}

func selDebugf(format string, args ...any) {
	if selDebugEnabled {
		log.Printf("[selector][debug] "+format, args...)
	}
}

// ============================================================
// 辅助函数（user32 / gdi32 封装）
// ============================================================

func utf16Ptr(s string) *uint16 {
	p, _ := syscall.UTF16PtrFromString(s)
	return p
}

func utf16Slice(s string) []uint16 {
	p, err := syscall.UTF16FromString(s)
	if err != nil {
		return nil
	}
	return p
}

func getSystemMetrics(index int32) int32 {
	ret, _, _ := procGetSystemMetrics.Call(uintptr(index))
	return int32(ret)
}

func loadCursor(id uintptr) uintptr {
	ret, _, _ := procLoadCursorW.Call(0, id)
	return ret
}

func getCursorPos() (int32, int32) {
	var p POINT
	ret, _, _ := procGetCursorPos.Call(uintptr(unsafe.Pointer(&p)))
	if ret == 0 {
		return 0, 0
	}
	return p.X, p.Y
}

func createWindowEx(exStyle uint32, className, windowName *uint16, style uint32,
	x, y, width, height int32, hwndParent, hMenu, hInstance, lpParam uintptr) uintptr {
	ret, _, _ := procCreateWindowExW.Call(
		uintptr(exStyle),
		uintptr(unsafe.Pointer(className)),
		uintptr(unsafe.Pointer(windowName)),
		uintptr(style),
		uintptr(x), uintptr(y), uintptr(width), uintptr(height),
		hwndParent, hMenu, hInstance, lpParam,
	)
	return ret
}

func showWindow(hwnd uintptr, nCmdShow int32) { procShowWindow.Call(hwnd, uintptr(nCmdShow)) }
func updateWindow(hwnd uintptr)               { procUpdateWindow.Call(hwnd) }
func translateMessage(msg *MSG)               { procTranslateMessage.Call(uintptr(unsafe.Pointer(msg))) }
func dispatchMessage(msg *MSG)                { procDispatchMessageW.Call(uintptr(unsafe.Pointer(msg))) }
func destroyWindow(hwnd uintptr)              { procDestroyWindow.Call(hwnd) }
func setForegroundWindow(hwnd uintptr)        { procSetForegroundWindow.Call(hwnd) }
func setFocus(hwnd uintptr)                   { procSetFocus.Call(hwnd) }

func getMessage(msg *MSG, hwnd uintptr, msgFilterMin, msgFilterMax uint32) int32 {
	ret, _, _ := procGetMessageW.Call(
		uintptr(unsafe.Pointer(msg)), hwnd, uintptr(msgFilterMin), uintptr(msgFilterMax))
	return int32(ret)
}

func getDC(hwnd uintptr) uintptr  { ret, _, _ := procGetDC.Call(hwnd); return ret }
func releaseDC(hwnd, hdc uintptr) { procReleaseDC.Call(hwnd, hdc) }

func setLayeredWindowAttributes(hwnd uintptr, crKey uintptr, bAlpha byte, dwFlags uint32) bool {
	ret, _, _ := procSetLayeredWindowAttributes.Call(hwnd, crKey, uintptr(bAlpha), uintptr(dwFlags))
	return ret != 0
}

func updateLayeredWindow(hwnd, hdcDst uintptr, ptDst *POINT, size *SIZE, hdcSrc uintptr,
	ptSrc *POINT, crColorKey uint32, blend *BLENDFUNCTION, dwFlags uint32) bool {
	ret, _, _ := procUpdateLayeredWindow.Call(
		hwnd, hdcDst, uintptr(unsafe.Pointer(ptDst)), uintptr(unsafe.Pointer(size)),
		hdcSrc, uintptr(unsafe.Pointer(ptSrc)), uintptr(crColorKey),
		uintptr(unsafe.Pointer(blend)), uintptr(dwFlags))
	return ret != 0
}

func createCompatibleDC(hdc uintptr) uintptr {
	ret, _, _ := procCreateCompatibleDC.Call(hdc)
	return ret
}
func deleteDC(hdc uintptr) bool { ret, _, _ := procDeleteDC.Call(hdc); return ret != 0 }

func createDIBSection(hdc uintptr, bmi *BITMAPINFO, bits **byte) uintptr {
	ret, _, _ := procCreateDIBSection.Call(
		hdc, uintptr(unsafe.Pointer(bmi)), 0,
		uintptr(unsafe.Pointer(bits)), 0, 0)
	return ret
}

func selectObject(hdc, obj uintptr) uintptr { ret, _, _ := procSelectObject.Call(hdc, obj); return ret }
func deleteObject(obj uintptr) bool         { ret, _, _ := procDeleteObject.Call(obj); return ret != 0 }

func bitBlt(hdcDest uintptr, x, y, width, height int32, hdcSrc uintptr,
	xSrc, ySrc int32, rop uint32) bool {
	ret, _, _ := procBitBlt.Call(
		hdcDest, uintptr(x), uintptr(y), uintptr(width), uintptr(height),
		hdcSrc, uintptr(xSrc), uintptr(ySrc), uintptr(rop))
	return ret != 0
}

func defWindowProc(hwnd, msg, wParam, lParam uintptr) uintptr {
	ret, _, _ := procDefWindowProcW.Call(hwnd, msg, wParam, lParam)
	return ret
}

// getPixelColor 读取屏幕真实像素颜色（不受遮罩影响），返回 r/g/b
func getPixelColor(hdc uintptr, x, y, sw, sh int) (byte, byte, byte) {
	if x < 0 || y < 0 || x >= sw || y >= sh {
		return 0, 0, 0
	}
	ret, _, _ := procGetPixel.Call(hdc, uintptr(x), uintptr(y))
	b := byte(ret & 0xFF)
	g := byte((ret >> 8) & 0xFF)
	r := byte((ret >> 16) & 0xFF)
	return r, g, b
}

// ============================================================
// 字体（懒加载，全局复用，避免热路径反复创建）
// ============================================================

var (
	gFont    uintptr
	fontOnce sync.Once
)

func ensureFont() {
	fontOnce.Do(func() {
		name := utf16Ptr("Microsoft YaHei UI")
		if name == nil {
			name = utf16Ptr("Segoe UI")
		}
		fontHeight := int32(-13)
		gFont, _, _ = procCreateFontW.Call(
			uintptr(fontHeight), 0, 0, 0, uintptr(FW_NORMAL),
			0, 0, 0, 0,
			0, 0, 0, 0, 0,
			uintptr(unsafe.Pointer(name)),
		)
	})
}

// drawTextOn 在 hdc（当前选中的 DIB）上绘制白色文字（背景需提前填充）
func drawTextOn(hdc uintptr, x, y int, s string, cr uint32) {
	if gFont == 0 {
		return
	}
	u := utf16Slice(s)
	if len(u) <= 1 {
		return
	}
	old := selectObject(hdc, gFont)
	procSetTextColor.Call(hdc, uintptr(cr))
	procSetBkMode.Call(hdc, uintptr(TRANSPARENT))
	procTextOutW.Call(hdc, uintptr(x), uintptr(y),
		uintptr(unsafe.Pointer(&u[0])), uintptr(len(u)-1))
	selectObject(hdc, old)
}

// ============================================================
// DIB 直接像素写入辅助（32 位 BGRA，自上而下）
// ============================================================

func dibFill(dib []byte, sw, sh, x0, y0, x1, y1 int, r, g, b byte) {
	if x0 < 0 {
		x0 = 0
	}
	if y0 < 0 {
		y0 = 0
	}
	if x1 > sw {
		x1 = sw
	}
	if y1 > sh {
		y1 = sh
	}
	for y := y0; y < y1; y++ {
		row := y * sw * 4
		for x := x0; x < x1; x++ {
			o := row + x*4
			dib[o] = b
			dib[o+1] = g
			dib[o+2] = r
			dib[o+3] = 255
		}
	}
}

func dibStrokeRect(dib []byte, sw, sh, x0, y0, x1, y1, thick int, r, g, b byte) {
	dibFill(dib, sw, sh, x0, y0, x1, y0+thick, r, g, b) // 上
	dibFill(dib, sw, sh, x0, y1-thick, x1, y1, r, g, b) // 下
	dibFill(dib, sw, sh, x0, y0, x0+thick, y1, r, g, b) // 左
	dibFill(dib, sw, sh, x1-thick, y0, x1, y1, r, g, b) // 右
}

// dibDim 对像素缓冲做压暗（各通道除以 2），实现微信式半透明遮罩效果。
// 仅在 prepareRender 中对底图执行一次，不再每帧重复。
func dibDim(dib []byte, sw, sh int) {
	n := sw * sh * 4
	for i := 0; i < n; i += 4 {
		dib[i] /= 2    // B
		dib[i+1] /= 2  // G
		dib[i+2] /= 2  // R
		dib[i+3] = 255 // A
	}
}

// ============================================================
// 渲染上下文（冻结屏幕 + 离屏合成，避免每帧重复抓屏/建位图/压暗）
// ============================================================

// renderCtx 在进入选区时一次性抓取整屏并预生成压暗底图；此后每帧只做
// 一次内存拷贝 + 局部绘制 + 一次 BitBlt 到窗口，从而显著降低重绘开销。
type renderCtx struct {
	w        int
	h        int
	rawBits  []byte  // 冻结的原始屏幕（BGRA）
	dimBits  []byte  // 预压暗的底图（BGRA），作为每帧合成基准
	workDC   uintptr // 离屏内存 DC，选中 workBmp，供 GDI 文字绘制
	workBmp  uintptr // 合成目标 DIB Section
	workBits []byte  // workBmp 像素内存（与 workDC 共享）
	oldBmp   uintptr // workDC 原对象，用于还原
}

// gRender 全局渲染上下文（doRedraw 在窗口过程回调中无法传参）
var gRender *renderCtx

// prepareRender 抓屏并构建渲染上下文。必须在显示遮罩窗口之前调用，
// 否则会把窗口自身画布抓进去（造成自捕获/逐帧变暗）。
func prepareRender() *renderCtx {
	sw := getSystemMetrics(SM_CXSCREEN)
	sh := getSystemMetrics(SM_CYSCREEN)
	if sw <= 0 || sh <= 0 {
		return nil
	}
	screenDC := getDC(0)
	if screenDC == 0 {
		return nil
	}
	defer releaseDC(0, screenDC)

	n := int(sw) * int(sh) * 4
	rc := &renderCtx{w: int(sw), h: int(sh)}
	rc.rawBits = make([]byte, n)
	rc.dimBits = make([]byte, n)

	bmi := &BITMAPINFO{}
	bmi.BmiHeader.BiSize = uint32(unsafe.Sizeof(bmi.BmiHeader))
	bmi.BmiHeader.BiWidth = sw
	bmi.BmiHeader.BiHeight = -sh
	bmi.BmiHeader.BiPlanes = 1
	bmi.BmiHeader.BiBitCount = 32
	bmi.BmiHeader.BiCompression = 0

	// 一次性抓屏：临时 DIB + BitBlt，把像素复制进 rawBits。
	tmpDC := createCompatibleDC(screenDC)
	if tmpDC == 0 {
		return nil
	}
	defer deleteDC(tmpDC)
	var bits *byte
	hTmp := createDIBSection(screenDC, bmi, &bits)
	if hTmp == 0 || bits == nil {
		return nil
	}
	defer deleteObject(hTmp)
	old := selectObject(tmpDC, hTmp)
	bitBlt(tmpDC, 0, 0, sw, sh, screenDC, 0, 0, SRCCOPY)
	copy(rc.rawBits, unsafe.Slice(bits, n))
	selectObject(tmpDC, old)

	// 预生成压暗底图（只做一次）。
	copy(rc.dimBits, rc.rawBits)
	dibDim(rc.dimBits, rc.w, rc.h)

	// 持久的合成目标 DIB（供每帧写入 + GDI 文字）。
	rc.workDC = createCompatibleDC(screenDC)
	if rc.workDC == 0 {
		return nil
	}
	var wbits *byte
	rc.workBmp = createDIBSection(screenDC, bmi, &wbits)
	if rc.workBmp == 0 || wbits == nil {
		deleteDC(rc.workDC)
		rc.workDC = 0
		return nil
	}
	rc.oldBmp = selectObject(rc.workDC, rc.workBmp)
	rc.workBits = unsafe.Slice(wbits, n)
	return rc
}

// releaseRender 释放渲染上下文持有的 GDI 资源。
func releaseRender(rc *renderCtx) {
	if rc == nil {
		return
	}
	if rc.workDC != 0 {
		if rc.oldBmp != 0 {
			selectObject(rc.workDC, rc.oldBmp)
		}
		if rc.workBmp != 0 {
			deleteObject(rc.workBmp)
		}
		deleteDC(rc.workDC)
	}
}

// restoreRegion 把选区对应的原始像素从 raw 拷回 dst（同布局，按行 memcpy）。
func restoreRegion(dst, raw []byte, sw, sh int, r image.Rectangle) {
	x0, y0 := r.Min.X, r.Min.Y
	x1, y1 := r.Max.X, r.Max.Y
	if x0 < 0 {
		x0 = 0
	}
	if y0 < 0 {
		y0 = 0
	}
	if x1 > sw {
		x1 = sw
	}
	if y1 > sh {
		y1 = sh
	}
	rowBytes := (x1 - x0) * 4
	if rowBytes <= 0 {
		return
	}
	for y := y0; y < y1; y++ {
		o := (y*sw + x0) * 4
		copy(dst[o:o+rowBytes], raw[o:o+rowBytes])
	}
}

// rawPixelColor 从冻结屏幕缓冲读取指定像素的 r/g/b（越界返回黑）。
func rawPixelColor(raw []byte, sw, sh, x, y int) (byte, byte, byte) {
	if x < 0 || y < 0 || x >= sw || y >= sh {
		return 0, 0, 0
	}
	o := (y*sw + x) * 4
	return raw[o+2], raw[o+1], raw[o] // R,G,B
}

// ============================================================
// 放大镜绘制
// ============================================================

// drawMagnifier 在 work 上绘制跟随光标的放大镜；颜色从冻结的 raw 读取（无 GetPixel）。
func drawMagnifier(work, raw []byte, workDC uintptr, sw, sh, cx, cy int) {
	content := magContent
	innerW := content
	outerW := innerW + 2*magFrame
	outerH := content + magLabelH + 2*magFrame

	ox := cx + magGap
	oy := cy + magGap
	if ox+outerW > sw {
		ox = cx - magGap - outerW
	}
	if oy+outerH > sh {
		oy = cy - magGap - outerH
	}
	if ox < 0 {
		ox = 0
	}
	if oy < 0 {
		oy = 0
	}

	// 黑色外框 + 底色
	dibFill(work, sw, sh, ox, oy, ox+outerW, oy+outerH, 0, 0, 0)

	contentX := ox + magFrame
	contentY := oy + magFrame

	// 逐源像素放大绘制
	for j := 0; j < magSrc; j++ {
		for i := 0; i < magSrc; i++ {
			r, g, b := rawPixelColor(raw, sw, sh, cx-magHalf+i, cy-magHalf+j)
			bx0 := contentX + i*magScale
			by0 := contentY + j*magScale
			dibFill(work, sw, sh, bx0, by0, bx0+magScale, by0+magScale, r, g, b)
		}
	}

	// 网格线
	for i := 1; i < magSrc; i++ {
		gx := contentX + i*magScale
		dibFill(work, sw, sh, gx, contentY, gx+1, contentY+content, gridR, gridG, gridB)
		gy := contentY + i*magScale
		dibFill(work, sw, sh, contentX, gy, contentX+content, gy+1, gridR, gridG, gridB)
	}

	// 中心红色十字（围绕中心像素）
	px0 := contentX + magHalf*magScale
	py0 := contentY + magHalf*magScale
	dibStrokeRect(work, sw, sh, px0-2, py0-2, px0+magScale+2, py0+magScale+2, 2, crossR, crossG, crossB)

	// 标签区
	labelY := contentY + content
	dibFill(work, sw, sh, contentX, labelY, contentX+innerW, labelY+magLabelH, 20, 20, 20)

	r, g, b := rawPixelColor(raw, sw, sh, cx, cy)
	line1 := fmt.Sprintf("坐标: %d, %d", cx, cy)
	line2 := fmt.Sprintf("#%02X%02X%02X  RGB(%d,%d,%d)", r, g, b, r, g, b)
	drawTextOn(workDC, contentX+4, labelY+3, line1, 0x00FFFFFF)
	drawTextOn(workDC, contentX+4, labelY+25, line2, 0x00FFFFFF)
}

// ============================================================
// 窗口过程
// ============================================================

func windowProc(hwnd uintptr, msg uint32, wParam, lParam uintptr) uintptr {
	// lParam：低 16 位为 x，高 16 位为 y。本工具窗口铺满主屏（位置 0,0），
	// 因此客户端坐标 == 屏幕坐标，可直接用于后续截取。
	x := int32(uint16(lParam & 0xFFFF))
	y := int32(uint16((lParam >> 16) & 0xFFFF))

	switch msg {
	case WM_MOUSEMOVE:
		// 始终更新光标位置（放大镜/十字依赖）；拖框中同步更新选区终点。
		gState.mu.Lock()
		gState.curX, gState.curY = x, y
		if gState.pressing {
			gState.endX, gState.endY = x, y
		}
		pressing := gState.pressing
		gState.mu.Unlock()
		selDebugf("mousemove (%d,%d) pressing=%v", x, y, pressing) // 高频，仅 debug
		doRedraw(hwnd, gState)

	case WM_LBUTTONDOWN:
		gState.mu.Lock()
		// 情况 A：点击落在已有选区内部 -> 保留当前选区（便于“双击确认”），不起新框。
		if gState.hasSel && !gState.pressing && gState.containsLocked(x, y) {
			gState.mu.Unlock()
			selLogf("mousedown (%d,%d) 命中已选区内部 -> 保留选区，等待双击确认", x, y)
			break
		}
		// 情况 B：其余情况一律开始一次新的拖框选择。
		gState.pressing = true
		gState.hasSel = false
		gState.startX, gState.startY = x, y
		gState.endX, gState.endY = x, y
		gState.curX, gState.curY = x, y
		gState.mu.Unlock()
		selLogf("mousedown (%d,%d) -> 开始拖框（起点已锁定）", x, y)
		// 捕获鼠标：保证指针移出窗口时仍能收到 move/up。
		procSetCapture.Call(hwnd)
		doRedraw(hwnd, gState)

	case WM_LBUTTONUP:
		gState.mu.Lock()
		if gState.pressing {
			gState.pressing = false
			gState.endX, gState.endY = x, y
			procReleaseCapture.Call()
			rect := gState.getRectLocked()
			// 过小（近似单击）视为无效选区；有效则标记 hasSel 并等待确认。
			gState.hasSel = rect.Dx() >= 2 && rect.Dy() >= 2
			valid := gState.hasSel
			gState.mu.Unlock()
			selLogf("mouseup (%d,%d) -> 选区 %dx%d @(%d,%d) 有效=%v", x, y, rect.Dx(), rect.Dy(), rect.Min.X, rect.Min.Y, valid)
		} else {
			gState.mu.Unlock()
		}
		doRedraw(hwnd, gState)

	case WM_LBUTTONDBLCLK:
		// 双击选区内部 -> 确认并退出。
		gState.mu.Lock()
		confirmed := gState.hasSel && gState.containsLocked(x, y)
		if confirmed {
			gState.done = true
		}
		gState.mu.Unlock()
		selLogf("dblclick (%d,%d) -> 确认=%v", x, y, confirmed)

	case WM_RBUTTONUP:
		// 右键 -> 取消。
		gState.mu.Lock()
		gState.cancel = true
		gState.done = true
		gState.mu.Unlock()
		selLogf("rightup -> 取消选区")

	case WM_KEYDOWN:
		gState.mu.Lock()
		switch wParam {
		case uintptr(VK_ESCAPE):
			gState.cancel = true
			gState.done = true
			gState.mu.Unlock()
			selLogf("keydown ESC -> 取消")
		case uintptr(VK_RETURN):
			if gState.hasSel {
				gState.done = true
				gState.mu.Unlock()
				selLogf("keydown ENTER -> 确认当前选区")
			} else {
				gState.mu.Unlock()
				selLogf("keydown ENTER -> 无有效选区，忽略")
			}
		default:
			gState.mu.Unlock()
		}

	case WM_PAINT:
		// 系统要求重绘（首次显示、被其它窗口遮挡后失效等）：用 BeginPaint/EndPaint
		// 校验无效区域，避免 WM_PAINT 反复触发；实际内容仍由 doRedraw 全量合成。
		var ps PAINTSTRUCT
		procBeginPaint.Call(hwnd, uintptr(unsafe.Pointer(&ps)))
		doRedraw(hwnd, gState)
		procEndPaint.Call(hwnd, uintptr(unsafe.Pointer(&ps)))

	case WM_ERASEBKGND:
		// 我们自己每帧全量绘制，跳过默认擦除背景，避免闪烁。
		return 1

	case WM_DESTROY:
		selLogf("destroy -> 投递退出消息 PostQuitMessage")
		procPostQuitMessage.Call(0)
	}

	return defWindowProc(hwnd, uintptr(msg), wParam, lParam)
}

// ============================================================
// 绘制
// ============================================================

// doRedraw 每次输入事件被调用一次：基于进入选区时冻结的渲染上下文 gRender 合成
// “遮罩 + 选区 + 放大镜 + 提示”，最后 BitBlt 到窗口。关键性能点：
//   - 不再每帧抓屏/建位图/全量压暗，这些在 prepareRender 里只做一次；
//   - 每帧仅做一次整屏内存拷贝（dimBits -> workBits）+ 局部绘制 + 一次 BitBlt；
//   - 放大镜从冻结的 rawBits 取色，去掉每帧上百次 GetPixel 系统调用。
//
// 完全依据 selectorState 快照绘制，可安全高频调用。
func doRedraw(hwnd uintptr, st *selectorState) {
	rc := gRender
	if rc == nil {
		selLogf("redraw 跳过：渲染上下文未初始化")
		return
	}
	start := time.Now()

	// 步骤 0：持锁读取状态快照，后续绘制基于快照，避免与消息处理并发竞争。
	st.mu.Lock()
	pressing := st.pressing
	hasSel := st.hasSel
	cx, cy := int(st.curX), int(st.curY)

	var selRect image.Rectangle
	showSel := pressing || hasSel
	if showSel {
		selRect = st.getRectLocked()
	}
	st.mu.Unlock()

	sw, sh := rc.w, rc.h
	work := rc.workBits

	// 步骤 1：每帧以“预压暗底图”为基准——一次整屏内存拷贝即可得到遮罩效果。
	copy(work, rc.dimBits)

	// 步骤 2：把选区区域从冻结的原始屏幕恢复为正常亮度，再叠加边框与尺寸提示。
	if showSel && selRect.Dx() > 0 && selRect.Dy() > 0 {
		restoreRegion(work, rc.rawBits, sw, sh, selRect)

		// 边框：先 1px 黑色描边再 2px 彩色线，增强在任意背景上的对比度。
		dibStrokeRect(work, sw, sh,
			selRect.Min.X-1, selRect.Min.Y-1, selRect.Max.X+1, selRect.Max.Y+1, 1, 0, 0, 0)
		dibStrokeRect(work, sw, sh,
			selRect.Min.X, selRect.Min.Y, selRect.Max.X, selRect.Max.Y, 2, borderR, borderG, borderB)

		// 尺寸提示：在选区左上角外侧画一个黑底小条，写上“宽 × 高”。
		sizeText := fmt.Sprintf("%d × %d", selRect.Dx(), selRect.Dy())
		boxW := len([]rune(sizeText))*9 + 12
		boxH := 22
		bx := selRect.Min.X
		by := selRect.Min.Y - boxH - 2
		if by < 0 {
			by = selRect.Min.Y + 2
		}
		dibFill(work, sw, sh, bx, by, bx+boxW, by+boxH, 0, 0, 0)
		drawTextOn(rc.workDC, bx+6, by+3, sizeText, 0x00FFFFFF)
		selDebugf("redraw 选区 %dx%d @(%d,%d)", selRect.Dx(), selRect.Dy(), selRect.Min.X, selRect.Min.Y)
	}

	// 步骤 3：放大镜——只要尚未确定选区（待选）或正在拖框，就实时跟随光标绘制。
	if !hasSel || pressing {
		drawMagnifier(work, rc.rawBits, rc.workDC, sw, sh, cx, cy)
	}

	// 步骤 4：选区确定后（已拖完、未再次拖框），在选区右下方给出操作提示文字。
	if hasSel && !pressing {
		hint := "双击选区 或 按 回车 确认 · 拖动可重新选择 · 右键 / ESC 取消"
		boxW := len([]rune(hint))*8 + 16
		boxH := 24
		bx := selRect.Max.X - boxW
		by := selRect.Max.Y + 6
		if bx < 0 {
			bx = 0
		}
		if by+boxH > sh {
			by = selRect.Max.Y - boxH - 6
		}
		dibFill(work, sw, sh, bx, by, bx+boxW, by+boxH, 0, 0, 0)
		drawTextOn(rc.workDC, bx+8, by+4, hint, 0x00FFFFFF)
	}

	// 步骤 5：把合成好的画面从离屏 workDC 拷到窗口 DC（普通不透明全屏窗口）。
	winDC := getDC(hwnd)
	if winDC == 0 {
		selLogf("redraw 失败：getDC(hwnd) 返回 0（窗口可能已销毁）")
		return
	}
	defer releaseDC(hwnd, winDC)

	if !bitBlt(winDC, 0, 0, int32(sw), int32(sh), rc.workDC, 0, 0, SRCCOPY) {
		selDebugf("redraw：向窗口 BitBlt 返回 FALSE")
	}
	selDebugf("redraw 完成 用时=%s pressing=%v hasSel=%v", time.Since(start), pressing, hasSel)
}

// ============================================================
// 主入口
// ============================================================

// selectRegionWindows 是 Windows 选区的主流程：建窗 -> 消息循环 -> 返回结果。
// 返回 (rect, ok, err)：ok=true 代用户已确认 rect；ok=false 代表取消/无有效选区。
func selectRegionWindows() (image.Rectangle, bool, error) {
	// 关键：Win32 消息循环要求“建窗线程 == 泵消息线程”。Go 协程会在多个
	// OS 线程间漂移，若不锁定，CreateWindowEx 与 GetMessage 可能不在同一线程，
	// 导致本窗口消息永远取不到 -> 不重绘/不响应（表现为“未响应”）。
	// 因此将当前协程钉死在一个 OS 线程上，直到选区流程结束。
	runtime.LockOSThread()
	defer runtime.UnlockOSThread()

	selLogf("=== 启动选区截图工具 ===")

	st := &selectorState{}
	gState = st

	// 预先记录光标位置，使放大镜/十字在首帧即可显示。
	st.curX, st.curY = getCursorPos()
	selLogf("初始光标位置 (%d,%d)", st.curX, st.curY)

	screenW := getSystemMetrics(SM_CXSCREEN)
	screenH := getSystemMetrics(SM_CYSCREEN)
	selLogf("主屏幕尺寸 %dx%d", screenW, screenH)

	// 步骤 0：在遮罩窗口显示之前先“冻结”一帧屏幕，构建渲染上下文。
	// 必须在建窗/显示之前完成抓屏，否则会把自身画布抓进去（自捕获）。
	// 此后每帧重绘都基于这份静态底图，无需重复抓屏/建位图/压暗，显著提升响应速度。
	gRender = prepareRender()
	if gRender == nil {
		selLogf("步骤 0 失败：prepareRender 无法构建渲染上下文")
		return image.Rectangle{}, false, fmt.Errorf("prepareRender failed")
	}
	defer func() {
		releaseRender(gRender)
		gRender = nil
		selLogf("渲染上下文已释放")
	}()

	// 初始化文字字体（全局一次），供尺寸/坐标/颜色标签绘制。
	ensureFont()

	className := utf16Ptr("ScreenSelectorCls")
	hInstance, _, _ := procGetModuleHandleW.Call(0)

	// 步骤 1：注册窗口类。CS_DBLCLKS 开启双击消息；HCursor 设为十字光标。
	// LpfnWndProc 必须用 syscall.NewCallback 包装顶层函数（回调无法传参，因此用全局 gState）。
	wc := WNDCLASSW{
		Style:         CS_OWNDC | CS_DBLCLKS,
		LpfnWndProc:   syscall.NewCallback(windowProc),
		HInstance:     hInstance,
		HCursor:       loadCursor(IDC_CROSS),
		LpszClassName: className,
	}
	atom, _, err := procRegisterClassW.Call(uintptr(unsafe.Pointer(&wc)))
	if atom == 0 {
		selLogf("步骤 1 失败：注册窗口类出错: %v", err)
		return image.Rectangle{}, false, err
	}
	selLogf("步骤 1：窗口类已注册 atom=%d", atom)
	defer func() {
		procUnregisterClassW.Call(uintptr(unsafe.Pointer(className)), hInstance)
		selLogf("窗口类已注销")
	}()

	// 步骤 2：创建全屏、置顶的普通弹窗（WS_POPUP 无标题栏/边框）。不使用 WS_EX_LAYERED，
	// 直接以不透明方式覆盖整屏，规避分层渲染模式冲突导致的不可见问题。
	hwnd := createWindowEx(
		WS_EX_TOPMOST,
		className,
		utf16Ptr("selector"),
		WS_POPUP,
		0, 0, screenW, screenH,
		0, 0, hInstance, 0,
	)
	if hwnd == 0 {
		err := windows.GetLastError()
		selLogf("步骤 2 失败：CreateWindowEx 出错: %v", err)
		return image.Rectangle{}, false, err
	}
	selLogf("步骤 2：全屏窗口已创建 hwnd=%d", hwnd)

	// 步骤 3：显示/刷新，并抢占前台+键盘焦点（保证收到回车/ESC）。
	showWindow(hwnd, SW_SHOW)
	updateWindow(hwnd)
	setForegroundWindow(hwnd)
	setFocus(hwnd)
	selLogf("步骤 3：窗口已显示并激活，已取得焦点")

	// 步骤 4：首帧绘制（立即展示遮罩 + 光标位置的放大镜）。
	doRedraw(hwnd, st)

	// 步骤 5：消息循环，直到 done（确认/取消）或收到 WM_QUIT。
	// 选区确认/取消都由 windowProc 将 done 置位，故循环会及时退出。
	selLogf("步骤 5：进入消息循环，等待用户操作…")
	var msg MSG
	for {
		ret := getMessage(&msg, 0, 0, 0)
		if ret == 0 {
			selLogf("消息循环退出：GetMessage 返回 0（WM_QUIT）")
			break
		}
		translateMessage(&msg)
		dispatchMessage(&msg)

		st.mu.Lock()
		done := st.done
		st.mu.Unlock()
		if done {
			selLogf("消息循环退出：状态机 done=true")
			break
		}
	}

	// 步骤 6：销毁窗口。
	destroyWindow(hwnd)
	selLogf("步骤 6：窗口已销毁")

	// 步骤 7：输出最终结果。取消或无有效选区时 ok=false。
	st.mu.Lock()
	defer st.mu.Unlock()
	if st.cancel || !st.hasSel {
		selLogf("=== 结束：取消或无有效选区 (cancel=%v hasSel=%v) ===", st.cancel, st.hasSel)
		return image.Rectangle{}, false, nil
	}
	rect := st.getRectLocked()
	selLogf("=== 结束：确认选区 %dx%d @(%d,%d) ===", rect.Dx(), rect.Dy(), rect.Min.X, rect.Min.Y)
	return rect, true, nil
}
