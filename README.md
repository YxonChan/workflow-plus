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
   ```

3. **Start the engine**
   ```bash
   docker-compose up -d
   ```

The studio will be available at `http://localhost`.

## Deployment Modes

### Local Development

- Local development in this repository defaults to Docker.
- Typical operations such as `docker compose up -d`, `docker compose restart php`, or worker restarts are intended for local debugging unless explicitly stated otherwise.

### Production Deployment

- Production in this project may run as direct host deployment rather than Docker.
- When the online environment is direct deployment, do not mechanically reuse Docker commands from local troubleshooting notes.
- For production changes, first identify the real process manager in use, such as `systemd`, `supervisor`, `php-fpm`, `nginx`, `cron`, or manually started `php think` workers.
- Image, workflow, and video worker restarts in production should be executed with the host's actual service commands, not assumed container restarts.

### Operational Note

- Any troubleshooting or deployment instruction must explicitly distinguish `local Docker` from `production direct deployment`.
- If the current environment is unknown, verify the deployment mode first before giving restart or rollout commands.

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
