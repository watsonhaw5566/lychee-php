import { defineConfig } from 'vitepress'

export default defineConfig({
  title: 'Lychee PHP',
  description: '轻量级、IDE 友好的 PHP Web 框架',
  lang: 'zh-CN',

  head: [
    ['link', { rel: 'icon', href: '/favicon.png', type: 'image/png' }],
    ['link', { rel: 'shortcut icon', href: '/favicon.png', type: 'image/png' }],
  ],

  themeConfig: {
    nav: [
      { text: '首页', link: '/' },
      { text: '快速开始', link: '/guide/quickstart' },
      {
        text: '核心模块',
        items: [
          { text: '容器', link: '/container' },
          { text: '配置', link: '/config' },
          { text: '路由', link: '/routing' },
          { text: '中间件', link: '/middleware' },
          { text: 'HTTP', link: '/http' },
        ],
      },
      {
        text: '进阶',
        items: [
          { text: '会话', link: '/session' },
          { text: '模板引擎', link: '/view' },
          { text: '数据库', link: '/orm' },
          { text: '数据迁移', link: '/migration' },
          { text: '文件系统', link: '/filesystem' },
          { text: '缓存', link: '/cache' },
          { text: '日志', link: '/log' },
          { text: '队列', link: '/queue' },
          { text: '定时任务', link: '/cron' },
          { text: '认证', link: '/auth' },
          { text: '命令行', link: '/console' },
        ],
      },
    ],

    sidebar: [
      {
        text: '入门',
        items: [
          { text: '快速开始', link: '/guide/quickstart' },
        ],
      },
      {
        text: '核心',
        items: [
          { text: '容器 Container', link: '/container' },
          { text: '配置 Config', link: '/config' },
          { text: '路由 Routing', link: '/routing' },
          { text: '中间件 Middleware', link: '/middleware' },
          { text: 'HTTP 请求与响应', link: '/http' },
        ],
      },
      {
        text: '功能模块',
        items: [
          { text: '会话 Session', link: '/session' },
          { text: '模板引擎 View', link: '/view' },
          { text: '数据库 ORM', link: '/orm' },
          { text: '数据迁移 Migration', link: '/migration' },
          { text: '文件系统 Filesystem', link: '/filesystem' },
          { text: '缓存 Cache', link: '/cache' },
          { text: '日志 Log', link: '/log' },
          { text: '队列 Queue', link: '/queue' },
          { text: '定时任务 Cron', link: '/cron' },
          { text: '认证 Auth', link: '/auth' },
          { text: '命令行 Console', link: '/console' },
        ],
      },
    ],

    socialLinks: [
      { icon: 'github', link: 'https://github.com/watsonhaw5566/lychee-php' },
    ],

    footer: {
      message: 'Released under the MIT License.',
      copyright: 'Copyright © 2024 Lychee PHP',
    },

    search: {
      provider: 'local',
    },
  },
})
