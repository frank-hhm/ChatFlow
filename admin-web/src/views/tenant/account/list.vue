<template>
    <layout-body-content pageHeader >
        <template v-slot:page-header-left>
            租户客服列表
        </template>
        <template v-slot:page-header-right>
            <a-space>
                <a-tooltip content="刷新">
                    <a-button @click="toInit(true)" size="small"><icon-refresh /></a-button>
                </a-tooltip>
            </a-space>
        </template>
        <template v-slot:content="{
                    height
                }">
            <a-table :loading="initLoading" :data="lists" row-key="id" isLeaf :pagination="false" :scroll="{
                    x: '100%',
                    y: height - 39
                }" :table-layout-fixed="true">
                <template #empty></template>
                <template #columns>
                    <a-table-column title="公司" data-index="company_name" :width="120">
                        <template #cell="{ record }">
                            <div>{{ record.company_name }}</div>
                        </template>
                    </a-table-column>
                    <a-table-column title="状态" :fixed="isMobile ? undefined : 'right'" data-index="status"
                        align="center" :width="80">
                        <template #cell="{ record }">
                            <a-switch v-permission-disabled="'system-admin-status'" v-model="record.status.value"
                                :disabled="record.level < 1" size="small" type="round" :loading="record.loading"
                                :beforeChange="() => {
                    return (record.switch = true);
                }" @change=" onStatusChange($event, record)" :checked-value="1" :unchecked-value="0" />

                        </template>
                    </a-table-column>
                    <a-table-column title="操作" :fixed="isMobile ? undefined : 'right'" align="center" :width="140">
                        <template #cell="{ record }">
                            <a-space>
                                <a-button v-if="Number(adminInfo.level) < 1 || adminInfo.id == record.id"
                                    @click=" onCreate(record.id)" v-permission="'system-admin-update'"
                                    size="small">编辑</a-button>
                                <div v-if="record.level > 0" v-permission="'system-admin-delete'">
                                    <a-popconfirm content="确定删除吗？" @ok="onDelete(record.id)">
                                        <template #icon>
                                            <icon-exclamation-circle-fill type="red" />
                                        </template>
                                        <a-button size="small">删除</a-button>
                                    </a-popconfirm>
                                </div>
                            </a-space>
                        </template>
                    </a-table-column>
                </template>
            </a-table>
        </template>
        <template #footer>
            <page :listPage="listPage" @change="pageChange"></page>
        </template>
    </layout-body-content>
</template>

<script lang="ts" setup>
import {
    onMounted,
    ref,
    getCurrentInstance,
} from "vue";
import { getListTenantAccountApi, updateStatusTenantAccountApi, deleteTenantAccountApi } from "@/api/tenant/account";
import { PageLimitType, Result, ResultError } from "@/types";
import { useAdminStore, useAppStore } from "@/store";
import { useSetting } from "@/hooks/useSetting";
import { storeToRefs } from "pinia";

const labelColFlex = ref<string>("50px");

const { adminInfo } = storeToRefs(useAdminStore());
const { isMobile } = storeToRefs(useAppStore());

const {
    proxy,
    proxy: { $utils },
} = getCurrentInstance() as any;

const searchFormRef = ref<HTMLElement>();

const searchForm = ref<{
    account_like: string;
    time: string[];
}>({
    account_like: "",
    time: [],
});

const onSearchReset = () => {
    proxy?.$refs["searchFormRef"]?.resetFields()
    searchForm.value.time = []
    toInit(true);
}

const initLoading = ref<boolean>(true);

const createComponentRef = ref<HTMLElement>();

const lists = ref<any>([]);

const toInit = (isInit: boolean = false) => {
    if (isInit) {
        listPage.value.page = 1;
    }
    let obj: any = {};
    obj.page = listPage.value.page;
    obj.limit = listPage.value.limit;
    obj.account_like = searchForm.value.account_like;
    obj.time = searchForm.value.time;
    initLoading.value = true;
    getListTenantAccountApi(obj)
        .then((res: Result) => {
            initLoading.value = false;
            lists.value = res.data.data;
            listPage.value.total = res.data.total;

            setTimeout(() => {
                initLoading.value = false;
            }, 300);
        })
        .catch((error: ResultError) => {
            $utils.errorMsg(error);
        });
};

const onCreate = (id: number | string) => {
    proxy?.$refs["createComponentRef"]?.open(id, 1);
};


const listPage = ref<any>({
    total: 0,
    limit: useSetting().PageLimit.value,
    page: 1,
});

const pageChange = (res: PageLimitType) => {
    listPage.value = res;
    toInit();
};

const onDelete = (id: number | string) => {
    deleteTenantAccountApi({ id })
        .then((res: Result) => {
            toInit();
            $utils.successMsg(res.message);
        })
        .catch((err: ResultError) => {
            $utils.errorMsg(err);
        });
};

const onStatusChange = (val: any, row: any) => {
    if (row.switch === true) {
        row.loading = true;
        updateStatusTenantAccountApi({
            id: row.id,
            status: val,
        })
            .then((res: Result) => {
                row.loading = false;
                toInit();
                $utils.successMsg(res);
            })
            .catch((err: ResultError) => {
                row.loading = false;
                toInit();
                $utils.errorMsg(err);
            });
    }
};


onMounted(() => {
    toInit();
});
</script>
<style scoped></style>