# 电商模板图片交接清单

## 当前状态

- 已接入 8 个新主图方向、12 个新详情方向的定义、色板、构图和服务端规则。
- 2026-10-28 已收到全部 20 张图片并完成导入，新增模板全部为 `enabled: true`。按用户后续要求移除旧模板后，当前可选商品主图为 8 种、商品详情图为 12 种，均为本次新模板。
- 已验证图片格式、命名、比例和分辨率，查看总览并重点检查数码、服饰和美妆详情大图；源资源、构建资源和本地 HTTP 返回一致。
- 旧的 4 个主图、8 个详情模板已下架（`enabled: false`、`retired: true`），不会出现在选择列表，也不能提交新的生成请求。海报 2 种、模特试穿 3 种不变。
- 旧模板定义及参考图片仅保留用于历史兼容，不物理删除历史依赖：打开、导入或复用旧草稿时切换到新默认模板，历史成品的冻结请求不改写，仍可查看和微调。已排队任务不被本次清理修改。
- 已扩充现有商品身份保持、配色适配和详情排版方法。新默认主图为 `main-color-block`，默认详情为 `detail-brand-minimal`。
- 不需要再安装任何 Codex Skill，也不需要执行上游项目的脚本或配置它们的 API。
- `images-inbox` 保留用户生成的全部原图，导入时未修改原文件。模板图是提供给模型的视觉参考，不只是界面封面。

## 以后补充或替换图片

1. 打开本目录的 `全部图片提示词.md`，或逐个打开同名 `.txt` 文件。
2. 每条提示词单独生成一张图，按给定英文文件名保存到同一个文件夹。推荐使用本目录的 `images-inbox`，也可以使用你自己的文件夹。
3. 主图使用 **1:1**，最低 **1024×1024**；详情图使用 **2:3**，最低 **1024×1536**。推荐主图 1536×1536 / 2048×2048，详情 1536×2304 / 2048×3072。
4. 可以先生成一部分，不必等 20 张齐全。优先建议：`main-beauty-podium`、`main-food-table`、`main-tech-rim`、`main-fashion-flatlay`、`detail-beauty-soft`、`detail-food-warm`、`detail-tech-grid`、`detail-home-linen`。
5. 完成后把文件夹绝对路径发到当前聊天，我来检查视觉质量、导入和启用。

文件名的英文部分不要更改，允许 `.jpg`、`.jpeg`、`.png`、`.webp`。同一个名字只保留一个版本。不要生成四宫格模板合集、手机截图、带水印的参考图或把几张独立页面拼成预览海报。

模板参考图不写固定广告文字，避免在后续生成时带入无关品牌、数字或假文案。系统会按用户实际确认的标题、卖点和商品资料组织文字区。示例商品仅演示构图，不作为用户商品的事实来源。

## 图片清单

### 商品主图（8 张）

| 文件名 | 方向 |
| --- | --- |
| `main-beauty-podium.jpg` | 柔光美妆 · 台座主图 |
| `main-food-table.jpg` | 食光餐桌 · 食品主图 |
| `main-tech-rim.jpg` | 暗夜数码 · 轮廓主图 |
| `main-home-window.jpg` | 日光家居 · 空间主图 |
| `main-fashion-flatlay.jpg` | 服饰平铺 · 杂志主图 |
| `main-color-block.jpg` | 撞色几何 · 活力主图 |
| `main-luxury-dark.jpg` | 黑金质感 · 精品主图 |
| `main-fresh-clean.jpg` | 清透洗护 · 清新主图 |

### 商品详情图（12 张）

| 文件名 | 方向 |
| --- | --- |
| `detail-beauty-soft.jpg` | 柔光护肤 · 品牌详情 |
| `detail-beauty-clinical.jpg` | 净白秩序 · 洗护详情 |
| `detail-food-warm.jpg` | 暖食日常 · 食品详情 |
| `detail-food-fresh.jpg` | 清爽食集 · 饮品详情 |
| `detail-tech-grid.jpg` | 精密数码 · 网格详情 |
| `detail-tech-dark.jpg` | 深蓝科技 · 质感详情 |
| `detail-home-linen.jpg` | 棉麻日光 · 家居详情 |
| `detail-home-kitchen.jpg` | 有序厨房 · 日用详情 |
| `detail-fashion-editorial.jpg` | 服饰画报 · 穿搭详情 |
| `detail-bag-craft.jpg` | 鞋包工坊 · 工艺详情 |
| `detail-aplus-light.jpg` | 明亮 A+ · 模块详情 |
| `detail-brand-minimal.jpg` | 极简品牌 · 留白详情 |

## 维护者导入流程

要求 Python 3.9+ 与 Pillow。先做视觉检查，确认商品完整、同一图各模块外观一致、主图不是拼版、详情确实在同一连续画布内完成。尺寸检查不等于视觉质量合格。

```powershell
# 默认只检查，不写入项目。
python scripts/import_ecommerce_templates.py "C:\图片所在文件夹"

# 确认合格后，转成 JPEG、清理 EXIF、复制资源，最后启用本次导入的模板。
python scripts/import_ecommerce_templates.py "C:\图片所在文件夹" --apply

# 已存在同名参考时默认拒绝；明确需要替换时才加 --replace。
```

导入脚本验证文件名、重复名、图片格式、最小尺寸、比例、静态图片与安全路径；不会执行输入文件。缺图模板继续禁用，不会使用占位图冒充完成。导入失败时不提前激活模板。

源资源写入 `frontend/public/ecommerce-box/styles`；如已有本地前端构建，也同步相应的 `public/admin/ecommerce-box/styles` 图片。启用状态编译在前端包内，所以导入后仍须在 `frontend` 执行 `npm run build` 并部署新构建；重启当前项目 PHP 与电商 worker，然后确认页面、报价和生成请求。

```powershell
node --test frontend/scripts/test-ecommerce-domain.mjs
python scripts/test_import_ecommerce_templates.py
cd frontend
npm run type-check
npm run check:i18n
npm run build
```

共享定义位于 `app/resources/ecommerce_image_designs.json`，`manifest.json` 为图片交接规格。旧模板 ID、旧套图结果和冻结编辑快照不重命名。A+ 是视觉方向，不代表已满足任何平台当前的实际上传规格或审核规则。

## 来源与授权

适配创作方法，不下载上游示例图、不接入上游 API 或追踪链接，不采用其模型排名、收费规则、未经核验的平台尺寸以及拆分多页的强制流程。固定版本与原始 MIT 版权声明保存在共享目录和 `app/resources/ecommerce_templates.LICENSE.txt`；详见 `docs/prompt-skill-sources.md`。
