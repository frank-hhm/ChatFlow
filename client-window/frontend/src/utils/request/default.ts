
import axios, { AxiosRequestConfig, AxiosResponse } from 'axios'
import { baseApiUrl, getToken } from "@/utils"
import router from "@/router";
import { Result } from "@/types";
import { reactive, readonly } from 'vue';
import { useAccountStoreHook } from '@/store';

export const request = (params: any, options: any = {
    isAllow: true
}) => {
    return new Promise<Result>((resolve, reject) => {
        // 不在全局 defaults 上设置，避免污染其他请求
        const service = axios.create({
            baseURL: baseApiUrl() + (options.apiPath || "client/"),
            timeout: 80000,
            responseType: 'json'
        })

        service.interceptors.request.use(
            (config: AxiosRequestConfig) => {
                if (!config.headers) {
                    throw new Error(
                        `Expected 'config' and 'config.headers' not to be undefined`
                    );
                }
                let token: string | unknown = getToken();
                if (token && options.isAllow) {
                    config.headers['Authori-zation'] = 'Bearer ' + token
                }
                // 上传进度回调
                config.onUploadProgress = progressEvent => {
                    if (options.progress) {
                        // 修复：原代码 `- 1 | 0` 会导致进度永远差1%到不了100
                        options.progress.percent = Math.round(progressEvent.loaded / progressEvent.total * 100)
                        // options.progress.percent = progressEvent.loaded / progressEvent.total * 100 - 1 | 0
                    }
                };
                // 取消信号
                if (options.signal) {
                    config.signal = options.signal
                }
                return config
            }, error => {
                return Promise.reject(error)
            }
        )

        service.interceptors.response.use(
            (response: AxiosResponse) => {
                if (response.data.code === 700 || response.data.code === 701) {
                    useAccountStoreHook().resetToken()
                    useAccountStoreHook().setInfo({})
                    router.replace({
                        path: "/login",
                        query: {
                            redirect: router.currentRoute.value.fullPath
                        }
                    });
                }
                if (response.data.code === 700) {
                    return Promise.reject({
                        data: response.data,
                        code: 'ERRPR_AUTH'
                    });
                } else if (response.data.code !== 1) {
                    return Promise.reject({
                        data: response.data,
                        code: 'ERRPR'
                    });
                }
                return Promise.resolve(response.data)
            },
            error => {
                // ===== 核心新增：取消请求不抛异常，返回 CANCELLED =====
                if (axios.isCancel(error)) {
                    return Promise.reject({
                        code: 'CANCELLED',
                        message: '请求已取消'
                    })
                }
                // 网络错误（无 response）
                if (!error.response) {
                    return Promise.reject({
                        code: 'ERR_NETWORK',
                        message: error.message || '网络异常'
                    })
                }
                // 未登录 / token 失效
                if (error.response.data.code === 700 || error.response.data.code === 701) {
                    router.replace({
                        path: "/login",
                        query: {
                            redirect: router.currentRoute.value.fullPath
                        }
                    });
                }
                return Promise.reject({
                    data: error.response.data,
                    code: 'SERVER_ERRPR'
                })
            }
        )

        service(params).then((res: any) => {
            resolve(res)
        }).catch(res => {
            if (res) reject(res)
        })
    })
}

/**
 * 带进度追踪的请求包装
 * 返回 progress 响应式对象 + request 方法
 */
export const requestProgress = (params: any, options: any = {
    isAllow: true
}) => {
    const progress = reactive({ percent: 0 });
    return {
        progress: readonly(progress),
        request: request(params, Object.assign({
            progress
        }, options))
    };
};

export default { request, requestProgress }