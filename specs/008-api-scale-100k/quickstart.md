# Quickstart: API 十万级规模扩展

## 前置条件

- 已完成 `003-admin-notifications`、`004-admin-crontab-tasks`
- Docker Compose 可启动 `api`、`mysql`、`redis`
- 本特性分支合并后执行 `make migrate`

## 本地启动

```bash
cp .env.example .env
# 建议开发值
export WEBMAN_WORKERS=4
export QUEUE_CONSUMERS=2
export DB_POOL_MAX=20
export REDIS_POOL_MAX=15

make rebuild-api
make migrate
make up
```

确认进程（应含 `webman` HTTP workers + `redis-queue` consumers + `scheduled_tasks_runner` + push）:

```bash
docker compose exec api ps aux | grep -E 'WorkerMan|start.php'
```

## Sizing（十万级生产起步）

| 组件 | 建议 |
|------|------|
| API Pod | 2 副本 × 16 `WEBMAN_WORKERS`（8 核） |
| `QUEUE_CONSUMERS` | 4～8 / 副本 |
| MySQL | 8.4 单主；`max_connections` ≥ 副本×worker×`DB_POOL_MAX` + 余量 |
| Redis | 7.x；队列 + token + 缓存共用；`maxmemory-policy noeviction` |
| LB | Nginx / 云 LB 四层转发 8787 |

连接池规划示例：2 副本 × 16 worker × 30 pool = 960 理论上限 — 需压测下调或升 MySQL `max_connections`。

## 验证 US1：公告异步

```bash
# 种子大量学员（或测试 fixture）
docker compose exec api php vendor/bin/phinx seed:run -s DemoDataSeeder  # 若已扩展

# 管理端发公告 — 观察 API 快速返回
curl -s -o /dev/null -w '%{time_total}\n' \
  -H "Authorization: Bearer $STAFF_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"title":"scale test","body":"body"}' \
  http://localhost/api/admin/v1/notifications/announcements

# 查询 fan-out 进度
curl -s -H "Authorization: Bearer $STAFF_TOKEN" \
  http://localhost/api/admin/v1/notifications/dispatches/1 | jq .fan_out_status,.fan_out_done_count
```

期望：HTTP <3s；`fan_out_status` 从 `pending|running` → `completed`。

## 验证 US2：Token kick

```bash
# 使用 PHPUnit
docker compose run --rm api-test php vendor/bin/phpunit --filter TokenIndexKickTest
```

## 验证 US3：首页缓存

```bash
docker compose run --rm api-test php vendor/bin/phpunit --filter HomeCacheTest
apps/api/tests/perf/timing.sh  # 对比缓存前后
```

## 验证 US4：未读计数

```bash
docker compose run --rm api-test php vendor/bin/phpunit --filter UnreadCounterTest
```

## 验证 US5：支付异步

```bash
docker compose run --rm api-test php vendor/bin/phpunit --filter PaymentNotifyAsyncTest
```

## 验证 US6：压测

```bash
# 需安装 k6 或 wrk（见 load-smoke.sh 头部说明）
./apps/api/tests/perf/load-smoke.sh http://localhost
```

目标：500 并发目录读 + 200 并发进度，p95 <2s，成功率 ≥99%（多副本环境）。

## 故障排查

| 现象 | 检查 |
|------|------|
| 公告一直 `pending` | queue consumer 是否运行；Redis 队列深度 `LLEN` |
| 未读数不准 | `UnreadCounterService::rebuildFromDb(learnerId)`（实现后 CLI） |
| kick 仍慢 | `TOKEN_KICK_ALLOW_SCAN_FALLBACK` 是否为 0；索引是否双写 |
| 首页数据陈旧 | 缓存 TTL；管理端保存是否触发 DEL |
| `queue_down` health | redis-queue 配置与 Redis 连通 |

## 相关文档

- [spec.md](./spec.md)
- [research.md](./research.md)
- [contracts/queue-and-infra.md](./contracts/queue-and-infra.md)
- [tasks.md](./tasks.md)
