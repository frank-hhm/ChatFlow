<template>
    <div>
        <a-modal :title="operation == 'create' ? '添加租户' : '编辑租户'" @BeforeOk="onCreateOk" @BeforeCancel="close"
            :width="isMobile ? 'calc(100% - 20px)' : '800px'" :top="useSetting().ModalTop" class="modal"
            v-model:visible="visible" :align-center="false" title-align="start" render-to-body>
            <div v-loading="initLoading">
                <a-form :model="createForm" :layout="isMobile ? 'vertical' : 'horizontal'" ref="createRef"
                    :rules="createRules">
                    <a-row :gutter="20">
                        <a-col :md="12" :xs="24">
                            <a-form-item :label-col-flex="labelColFlex" label="公司名称" field="company_name">
                                <a-input v-model="createForm.company_name" placeholder="请输入公司名称"></a-input>
                            </a-form-item>
                        </a-col>
                    </a-row>
                    <a-row :gutter="20">
                        <a-col :md="12" :xs="24">
                            <a-form-item :label-col-flex="labelColFlex" label="邮箱" field="email">
                                <a-input v-model="createForm.email" placeholder="请输入邮箱"></a-input>
                            </a-form-item>
                        </a-col>
                        <a-col :md="12" :xs="24">
                            <a-form-item :label-col-flex="labelColFlex" label="昵称" field="nick_name">
                                <a-input v-model="createForm.nick_name" placeholder="请输入昵称"></a-input>
                            </a-form-item>
                        </a-col>
                    </a-row>
                    <a-row :gutter="20">
                        <a-col :md="12" :xs="24">
                            <a-form-item :label-col-flex="labelColFlex" label="密码：" field="pwd">
                                <a-input v-model="createForm.pwd" type="password" placeholder="请输入密码"></a-input>
                            </a-form-item>
                        </a-col>
                        <a-col :md="12" :xs="24">
                            <a-form-item :label-col-flex="labelColFlex" label="确定密码：" field="conf_pwd">
                                <a-input v-model="createForm.conf_pwd" type="password" placeholder="请再次输入密码"></a-input>
                            </a-form-item>
                        </a-col>
                    </a-row>
                </a-form>
            </div>
            <template #footer>
                <a-space>
                    <a-button @click="close()">取消</a-button>
                    <a-button type="primary" @click="onCreateOk()" :loading="btnLoading"
                        :disabled="initLoading || btnLoading">确定</a-button>
                </a-space>
            </template>
        </a-modal>
    </div>
</template>

<script lang="ts">
export default {
    name: "tenantCreate",
};
</script>
<script lang="ts" setup>
import { ref, getCurrentInstance, nextTick, reactive, onMounted } from "vue";
import { createTenantApi, updateTenantApi, getDetailTenantApi } from "@/api/tenant/tenant";
import { getRoleSelectList } from "@/api/system/role";
import { EnumItemType, Result, ResultError } from "@/types";
import { useEnumStore, useAppStore } from '@/store';
import { useSetting } from "@/hooks/useSetting";
import { storeToRefs } from "pinia";
import { ValidatedError } from "@arco-design/web-vue";

const { isMobile } = storeToRefs(useAppStore());

const {
    proxy,
    proxy: { $utils },
} = getCurrentInstance() as any;

const labelColFlex = ref<string>("80px");

const emit = defineEmits(["success"]);

const operation = ref<string>("create");

const operationId = ref<number>(0);


const visible = ref<boolean>(false);

const createRef = ref<HTMLElement>();

const createForm = ref<any>({
    company_name: "",
    email: "",
    nick_name: "",
    pwd: "",
    conf_pwd: "",
    status: 1,
});

const createRules: any = reactive({
    company_name: [{ required: true, message: "公司名称不能为空！", trigger: "blur" }],
    email: [
        { required: true, message: "邮箱不能为空！", trigger: "blur" },
        {
            validator: (value: string | undefined, callback: (error?: string) => void) => {
                console.log($utils.isEmail(value))
                if (!$utils.isEmail(value)) {
                    callback('邮箱格式不正确')
                } else {
                    callback()
                }
            }, trigger: "blur"
        },
    ],
    nick_name: [{ required: true, message: "昵称不能为空！", trigger: "blur" }],
});

const initLoading = ref<boolean>(false);

const toInit = () => {
    if (!operationId.value) {
        return;
    }
    initLoading.value = true;
    getDetailTenantApi({ id: operationId.value })
        .then((res: Result) => {
            createForm.value.company_name = res.data.company_name;
            createForm.value.nick_name = res.data.nick_name;
            createForm.value.email = res.data.email;
            createForm.value.status = res.data.status.value;
            initLoading.value = false;
        })
        .catch((err: ResultError) => {
            $utils.errorMsg(err);
        });
};

const btnLoading = ref<boolean>(false);

const onCreateOk = () => {
    proxy?.$refs['createRef']?.validate((error: undefined | Record<string, ValidatedError>) => {
        if (error === undefined) {
            if (btnLoading.value) {
                return;
            }
            btnLoading.value = true;
            let operationApi: any = null;
            if (operation.value == "create") {
                operationApi = createTenantApi(createForm.value);
            } else {
                operationApi = updateTenantApi(
                    Object.assign(
                        {
                            id: operationId.value,
                        },
                        createForm.value
                    )
                );
            }
            operationApi
                .then((res: Result) => {
                    $utils.successMsg(res.message);
                    close();
                    emit("success");
                    btnLoading.value = false;
                })
                .catch((err: ResultError) => {
                    $utils.errorMsg(err);
                    btnLoading.value = false;
                });
        }
    });
};

const open = (id: number = 0) => {
    if (id != 0) {
        operation.value = "update";
        operationId.value = id;
    } else {
        operation.value = "create";
        createRules["pwd"] = [
            { required: true, message: "请输入密码", trigger: "blur" },
        ];
        createRules["conf_pwd"] = [
            { required: true, message: "请再次密码", trigger: "blur" },
        ];
    }
    nextTick(() => {
        toInit();
    });
    visible.value = true;
};

const close = () => {
    proxy?.$refs['createRef']?.resetFields();
    operationId.value = 0;
    createRules.pwd = [];
    createRules.conf_pwd = [];
    visible.value = false;
    return true;
};

onMounted(() => {
});

defineExpose({ open });
</script>