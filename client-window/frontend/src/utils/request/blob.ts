// blob.ts
import axios, { AxiosRequestConfig, AxiosResponse } from 'axios'
import { baseApiUrl, getToken } from "@/utils"
import router from "@/router/index";
import { Result } from "@/types";
import { reactive, readonly } from 'vue';

export const request = (params: any, options: any = {
    isAllow: true
}) => {
    return new Promise<Result>((resolve, reject) => {
        const service = axios.create({
            baseURL: baseApiUrl(), // api 的 base_url
            timeout: 80000,
            responseType: 'blob' // blob 请求
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
                        options.progress.percent = Math.round(progressEvent.loaded / progressEvent.total * 100)
                    }
                };
                // 取消信号
                if (options.signal) {
                    config.signal = options.signal
                }
                return config
            },
            error => {
                return Promise.reject(error)
            }
        )

        service.interceptors.response.use(
            (response: AxiosResponse) => {
                return Promise.resolve({
                    data: response.data,
                    code: 1
                })
            },
            error => {
                // 取消请求时不报错
                if (axios.isCancel(error)) {
                    return Promise.reject({ code: 'CANCELLED', message: '已取消' })
                }
                if (error.code === 'ERR_NETWORK') {
                    return Promise.reject({ code: 'ERR_NETWORK', message: error.message || '网络异常' })
                }
                // 未登录 / token 失效
                if (error.response?.data?.code === 700 || error.response?.data?.code === 701) {
                    router.replace({
                        path: "/login",
                        query: {
                            redirect: router.currentRoute.value.fullPath
                        }
                    });
                }
                const msg = error.response?.data?.message

                    || error.message
                    || error.response?.statusText
                    || '请求失败'
                return Promise.reject({
                    code: error.response?.data?.code || 'ERROR',
                    message: msg
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

export const requestProgress = (params: any, options: any = {
    isAllow: true
}) => {
    const progress = reactive({ percent: 0 });
    return {
        progress: readonly(progress),
        request: request(params, Object.assign({ progress }, options))
    };
};

export default { request, requestProgress }