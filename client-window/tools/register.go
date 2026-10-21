package tools

import (
	"github.com/wailsapp/wails/v3/pkg/application"
)

// Services 返回 tools 目录下的所有 Wails 服务。
// 后续新增工具只需在这里追加即可，main.go 不需要改。
func Services() []application.Service {
	return []application.Service{
		application.NewService(&ScreenshotService{}),
	}
}
