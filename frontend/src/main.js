import { createApp } from 'vue'
import './scss/global.scss'
import App from './App.vue'
import i18n from './i18n'
import router from './router'
import { loadCurrentUser } from './composables/useCurrentUser'
import { showStartupLoader } from './lib/startupLoader'

const app = createApp(App).use(router).use(i18n)
showStartupLoader({
  label: i18n.global.t('app.loading'),
  ready: Promise.allSettled([
    router.isReady(),
    loadCurrentUser().catch((cause) => console.error('Unable to load the current Wave account.', cause)),
  ]),
})
app.mount('#app')
