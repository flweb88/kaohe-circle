# 考核小管家 / 考核圈

一个面向小团队的考核打卡与轻量社交网站，包含账号注册与登录、每日打卡、动态、关注、私信、通知、媒体上传和管理员功能。

## 技术栈

- 前端：原生 HTML、CSS、JavaScript，主页面为 `kaohe.html`
- 后端：PHP 8.2+，接口位于 `kaohe-api/`
- 数据：PHP 接口将运行数据写入项目根目录的 `kaohe-data/`；此目录默认不纳入 Git
- 部署脚本：`deploy.sh`，用于通过 SSH 将源码部署到 Synology Web Station

## 部署

1. 将仓库文件放入 Web Station 网站目录，并确保 PHP 8.2 或更新版本可用。
2. 让 PHP/Web Station 运行账号可以在 `kaohe-data/` 中创建和写入文件。不要把真实账号数据、上传媒体、备份或凭据提交到 Git。
3. 将 `kaohe-data/uploads/.htaccess` 一并部署，以禁止上传目录执行脚本。
4. 在管理员设置中配置邮件和通知服务。凭据保存在服务器上的 `kaohe-data/config.php`，不得提交该文件。
5. 为网站配置 HTTPS。站点域名上的 HTTP 页面会跳转到同域名的标准 HTTPS 端口；本地和 IP 地址访问不执行此跳转。

如使用 `deploy.sh`，先配置目标 NAS：

```sh
export KAOHE_NAS='用户名@NAS地址'
bash deploy.sh
```

部署前请确认 SSH 主机指纹可信，并确保目标 Web Station 路径与脚本中的默认路径一致。不要在公开仓库中保存 SSH 密钥或服务器密码。

## 本地预览

前端页面可以直接用浏览器打开。完整功能需要 PHP 后端；本地 PHP 开发服务器可从项目根目录启动：

```sh
php -S 127.0.0.1:8080
```

## 数据与安全

- `kaohe-data/`、`_backup/`、`_evidence/`、日志和导出的 PDF 默认由 `.gitignore` 排除。
- 生产环境应使用 HTTPS，并为数据目录配置最小必要权限和独立备份。
- 首次部署后应立即设置管理员账号、关闭不需要的注册入口，并配置邮件服务以支持找回密码。
- 仓库公开可见不等于代码已授予开源许可；目前没有附加开源许可证。
