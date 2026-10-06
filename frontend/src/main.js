import { createApp } from 'vue'
import './styles/global.scss'
import App from './App.vue'
import i18n from './i18n'
import router from './router'

createApp(App).use(router).use(i18n).mount('#app')
