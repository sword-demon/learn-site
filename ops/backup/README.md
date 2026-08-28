# 迁移前备份与恢复演练

这些脚本是数据库迁移的发布门禁。它们只通过 Docker Compose 调用 MySQL、API 和 Phinx，不在宿主机直接运行 PHP、MySQL 或迁移命令。

## 备份

```bash
make backup
```

备份目录包含 MySQL dump、`uploads` 卷快照、`phinx status`、当前迁移版本、Compose 镜像引用、`manifest.json` 和 `SHA256SUMS`。脚本拒绝覆盖非空目录，任一产物为空或无法计算 SHA-256 都会失败。

## 安全迁移

```bash
make migrate
```

迁移入口会先检查磁盘、数据库连接和当前版本，再创建同一备份目录，最后只执行一次 `phinx migrate`。迁移非零退出时会保留 `migrate.log` 和迁移输出，不会自动重试未知的半完成迁移。

## 恢复演练

```bash
BACKUP_DIR=/private/tmp/learn-site-backup-YYYYMMDDHHMMSS make rehearse-restore
```

恢复使用 `learn-site-restore-*` 临时 Compose project，因此数据库和上传卷不会覆盖当前项目。演练会校验 checksum、恢复 dump 和上传文件、关键读取/临时写入、媒体引用、Phinx 状态和 API 健康检查，结束时删除临时 project 及其卷。失败时保留备份目录和恢复日志。

生产破坏性变更不得直接执行未经隔离副本验证的 `down()`；应恢复已验证的数据库与上传卷，再执行前向迁移，或发布补偿迁移。
