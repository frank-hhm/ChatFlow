import { App, Plugin } from 'vue';
import copyright from '@/components/plugins/copyright/index.vue'
import shortcutsTime from '@/components/plugins/shortcuts-time/index.vue'
import videoPlay from '@/components/plugins/video-play/index.vue'
import searchMap from '@/components/plugins/search-map/index.vue'
import page from '@/components/plugins/page/index.vue'
import audioPlay from '@/components/plugins/audio-play/index.vue'
import textOverflow from '@/components/plugins/text-overflow/index.vue'

export const ComponentsPlugin: Plugin = {
    install(app: App) {
        app.component('copyright', copyright);
        app.component('shortcuts-time', shortcutsTime);
        app.component('video-play', videoPlay);
        app.component('search-map', searchMap);
        app.component('page', page);
        app.component('audio-play', audioPlay);
        app.component('text-overflow', textOverflow);

    },
};
export {  copyright,  shortcutsTime, videoPlay, searchMap, page, audioPlay,textOverflow };