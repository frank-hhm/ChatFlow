import { ref } from "vue"
import store, { useAccountStoreHook } from "@/store"
import { defineStore } from "pinia"
import { baseWsUrl, getToken, dispatchEvents } from "@/utils";
import router from "@/router";


let isWebSocketOpen = false

export const useWebsocketStore = defineStore("websocket", () => {
    const ws = ref<WebSocket | null>(null);

    const initWebSocket = async () => {
        const accountStore = useAccountStoreHook();
        const accountInfo = accountStore.accountInfo
        const token = getToken()
        console.log("======", token)
        if (!ws.value && token) {
            isWebSocketOpen = true;
            let wsUrl = baseWsUrl();
            wsUrl = baseWsUrl() + `?module=client&tenant_id=${accountInfo.tenant_id}&account_id=${accountInfo.id}` + '&token=' + `${getToken()}`;
            ws.value = new WebSocket(wsUrl);
            setupEventListeners(ws.value);
        }
    }

    let heartbeatIntervalId: any | unknown = null;

    const setupEventListeners = (websocket: WebSocket) => {
        heartbeatIntervalId = setInterval(() => {
            if (websocket?.readyState === WebSocket.OPEN) {
                // sendMessage({
                //     type: 'chat',
                //     content:"test"
                // });
            } else if (websocket?.readyState === WebSocket.CLOSED || websocket?.readyState === WebSocket.CLOSING) {
                clearInterval(heartbeatIntervalId!);
            }
        }, 10000);
        const handleClose = (event: CloseEvent) => {
            isWebSocketOpen = false;
            if (heartbeatIntervalId) {
                clearInterval(heartbeatIntervalId);
            }
            ws.value = null;
            setTimeout(() => {
                if (!isWebSocketOpen) {
                    initWebSocket();
                }
            }, 2000);
        }
        const handleMessage = (event: MessageEvent) => {
            const res = JSON.parse(event.data);
            console.log(res)
            if (res.code == 701) {
                router.replace({
                    path: "/login",
                    query: {
                        redirect: router.currentRoute.value.fullPath
                    }
                });
            } else {
                dispatchEvents('webSocketMessage', res);
            }
        }
        const handleOpen = (event: Event) => {
            sendMessage({});
        }
        const handleError = (error: Event | ErrorEvent) => {
            isWebSocketOpen = false;
            if (heartbeatIntervalId) {
                clearInterval(heartbeatIntervalId);
            }
            ws.value = null;
            setTimeout(() => {
                if (!isWebSocketOpen) {
                    initWebSocket();
                }
            }, 3000);
        }
        websocket.addEventListener('open', handleOpen);
        websocket.addEventListener('message', handleMessage);
        websocket.addEventListener('error', handleError);
        websocket.addEventListener('close', handleClose)
    }

    const sendMessage = (data: any) => {
        if (ws.value?.readyState === WebSocket.OPEN) {
            ws.value.send(JSON.stringify(data));
        }
    }
    const closeWebSocket = () => {
        if (ws.value?.readyState === WebSocket.OPEN) {
            ws.value.send(JSON.stringify({
                type: 'close'
            }));
        }
        // 先关掉自动重连
        isWebSocketOpen = false
        if (heartbeatIntervalId) {
            clearInterval(heartbeatIntervalId)
            heartbeatIntervalId = null
        }

        if (ws.value) {
            // 解绑事件，避免 close 后再触发重连
            ws.value.onopen = null
            ws.value.onmessage = null
            ws.value.onerror = null
            ws.value.onclose = null

            if (
                ws.value.readyState === WebSocket.OPEN ||
                ws.value.readyState === WebSocket.CONNECTING
            ) {
                ws.value.close()
            }

            ws.value = null
        }
    }
    return { ws, initWebSocket, sendMessage, closeWebSocket }
})
/** 在 setup 外使用 */
export function useWebsocketStoreHook() {
    return useWebsocketStore(store)
}
