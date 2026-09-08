# AI Workflow

A professional AI cinematic pipeline built with ThinkPHP 8.0.

[![License](https://img.shields.io/badge/license-Apache%202-blue.svg)](LICENSE.txt)
[![PHP](https://img.shields.io/badge/php-%3E%3D%208.2-777bb4.svg)](https://www.php.net/)
[![Framework](https://img.shields.io/badge/thinkphp-8.0-18bfff.svg)](https://github.com/top-think/framework)

AI Workflow is a high-performance, containerized SaaS platform for AI-driven film and video production. It integrates scriptwriting, image generation, video synthesis, and voice-over into a single, cohesive workflow.

## Key Features

- **Dynamic Model Engine**: Hot-swappable AI models (Text, Image, Video, Voice) via unified API adapters.
- **Studio-Grade UI**: A minimalist, high-performance frontend with zero external dependencies (local SVG icons).
- **Workflow Automation**: End-to-end pipeline from raw script to final render.
- **Docker First**: Production-ready environment with PHP 8.2-FPM, Nginx, MySQL, and Redis.
- **Modern PHP**: Fully typed codebase leveraging PHP 8.2+ features and ThinkPHP 8.0 architecture.

## Tech Stack

- **Backend**: ThinkPHP 8.0, PHP 8.2+
- **Frontend**: Vanilla JS, Modern CSS (Studio UI), Lucide Icons
- **Infrastructure**: Docker, Nginx, MySQL 8.0, Redis 7.0

## Getting Started

### Prerequisites

- Docker and Docker Compose
- Git

### Installation

1. **Clone the repository**
   ```bash
   git clone <your-repo-url>
   cd tp8image
   ```

2. **Setup environment**
   ```bash
   cp .example.env .env
   # 必须修改 DB_PASS，并填入实际 AI/Supabase 配置
   ```

3. **Start the engine**
   ```bash
   docker compose up -d --build
   ```

Compose 会启动 Nginx、PHP-FPM、MySQL、Redis 以及全部 `*:worker` 服务。本仓库本地工作台地址为 `http://localhost:8086`（`WEB_PORT`）。前端开发服务器固定 `http://localhost:5176/admin/`，API 代理指向 `8086`。

查看服务状态与 Worker 日志：

```bash
docker compose ps
docker compose logs -f worker video-worker asset-worker
```

## Deployment Modes

### Local Development

- Local development in this repository defaults to Docker.
- Typical operations such as `docker compose up -d`, `docker compose restart php`, or worker restarts are intended for local debugging unless explicitly stated otherwise.

### Production Deployment (宝塔)

- 宝塔服务器安装 Docker Engine 与 Compose Plugin 后，在项目根目录准备 `.env`。
- 使用 `bash deploy/deploy.sh`；脚本默认执行 `docker compose up -d --build`，PHP-FPM、Nginx、MySQL、Redis 与全部 Worker 均由 Docker 管理。
- 若需明确回退到旧的宿主机 PHP/systemd 部署，设置 `AI_WORKFLOW_USE_DOCKER=0` 后再执行脚本。
- 生产环境可设置 `AI_WORKFLOW_EXTERNAL_DB=1`，使用宝塔或独立 MySQL；此时 Docker 只运行 PHP-FPM、Nginx、Redis 与全部 Worker。

### Operational Note

- Troubleshooting commands must distinguish Docker Compose services from the optional direct-deployment fallback.

## Project Structure

```text
app/            # Core business logic (MVC + Service Layer)
config/         # Application configurations
docker/         # Container configurations & init scripts
public/         # Web root
  └── studio/   # Frontend application files
route/          # API & Web routes
runtime/        # Logs and cache
```

## API Documentation

The API follows standard RESTful conventions. Model configurations can be managed via:
- `GET /api/model-configs` - List all models
- `POST /api/model-configs` - Create new model
- `PUT /api/model-configs/:id` - Update model

## License

This project is open-sourced under the [Apache 2.0 license](LICENSE.txt).

---
*Maintained by the AI Workflow Team.*
