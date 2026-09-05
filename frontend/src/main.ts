import { createApp } from 'vue'
import { createPinia } from 'pinia'
import * as ElementPlusIconsVue from '@element-plus/icons-vue'
import App from './App.vue'
import router from './router'
import i18n from './i18n'
import { lazySrcDirective } from '@/directives/lazySrc'
import '@/utils/elMessageDefaults'
import '@/styles/index.scss'

const app = createApp(App)

app.directive('lazy-src', lazySrcDirective)

// 模板里 <el-icon><Share /></el-icon> 等依赖全局组件；未 import 时图标会空白
for (const [key, component] of Object.entries(ElementPlusIconsVue)) {
  app.component(key, component)
}

app.use(createPinia())
app.use(router)
app.use(i18n)

app.mount('#app')
