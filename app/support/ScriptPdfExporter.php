<?php

declare(strict_types=1);

namespace app\support;

use Mpdf\Mpdf;
use Mpdf\Output\Destination;

class ScriptPdfExporter
{
    public function export(array $project, string $author): string
    {
        $content = trim((string) ($project['final_content'] ?? ''));
        if ($content === '') {
            throw new \InvalidArgumentException('剧本尚无可导出的最终内容');
        }

        $title = trim((string) ($project['title'] ?? '')) ?: '未命名剧本';
        $author = trim($author) ?: '智梦工厂';
        $isChinese = true;

        $tempDir = $this->tempDirectory();
        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'Letter',
            'margin_left' => 37,
            'margin_right' => 24,
            'margin_top' => 22,
            'margin_bottom' => 19,
            'margin_header' => 10,
            'default_font' => $isChinese ? 'sun-exta' : 'courier',
            'tempDir' => $tempDir,
        ]);
        $mpdf->SetTitle($title);
        $mpdf->SetAuthor($author);
        $mpdf->SetCreator('智梦工厂 Intelligent Dream Factory');
        $mpdf->useSubstitutions = $isChinese;
        $mpdf->autoScriptToLang = $isChinese;
        $mpdf->autoLangToFont = $isChinese;

        $html = $this->buildHtml($title, $author, $content, $isChinese);
        $mpdf->WriteHTML($html);

        return $mpdf->Output('', Destination::STRING_RETURN);
    }

    private function buildHtml(string $title, string $author, string $content, bool $isChinese): string
    {
        $font = $isChinese ? 'sun-exta' : 'courier';
        $titleText = $isChinese ? $title : mb_strtoupper($title, 'UTF-8');
        $byline = $isChinese ? '编剧 ' . $author : 'Written by ' . $author;
        $body = $this->renderBlocks($this->parseBlocks($content));

        return '<!doctype html><html><head><meta charset="utf-8"><style>'
            . 'body { margin:0; color:#111; font-family:' . $font . '; font-size:11.5pt; line-height:1.12; }'
            . '.page-number { text-align:right; font-family:courier; font-size:11pt; color:#111; }'
            . '.cover { margin-left:-6.5mm; margin-right:6.5mm; padding-top:36mm; text-align:center; }'
            . '.cover-title { font-size:12pt; letter-spacing:.1mm; }'
            . '.cover-byline { margin-top:69mm; font-size:11.5pt; }'
            . '.script-body { width:100%; }'
            . '.block { margin:0 0 4.2mm; white-space:pre-wrap; overflow-wrap:break-word; }'
            . '.episode { margin:0 0 7mm; text-transform:uppercase; }'
            . '.scene { margin:0 0 4.5mm; font-weight:400; text-transform:uppercase; }'
            . '.action { margin:0 0 5mm; }'
            . '.transition { margin:0 0 7mm; text-align:right; text-transform:uppercase; }'
            . '.dialogue-unit { margin:0 0 5mm; page-break-inside:avoid; }'
            . '.character { width:92mm; margin:0 0 0 25mm; text-align:center; text-transform:uppercase; }'
            . '.parenthetical { width:70mm; margin:0 0 0 36mm; text-align:left; }'
            . '.dialogue { width:88mm; margin:0 0 0 25mm; text-align:left; }'
            . '.end { margin-top:10mm; text-transform:uppercase; }'
            . '</style></head><body>'
            . '<htmlpageheader name="scriptHeader"><div class="page-number">{PAGENO}.</div></htmlpageheader>'
            . '<div class="cover"><div class="cover-title">' . $this->escape($titleText) . '</div>'
            . '<div class="cover-byline">' . $this->escape($byline) . '</div></div>'
            . '<pagebreak odd-header-name="scriptHeader" odd-header-value="on" even-header-name="scriptHeader" even-header-value="on" />'
            . '<main class="script-body">' . $body . '</main>'
            . '</body></html>';
    }

    private function parseBlocks(string $content): array
    {
        $content = preg_replace('/^```(?:text|plaintext|markdown)?\s*|\s*```$/iu', '', trim($content)) ?? trim($content);
        $lines = preg_split('/\R/u', $content) ?: [];
        $blocks = [];
        $previousType = '';

        foreach ($lines as $index => $rawLine) {
            $line = $this->cleanLine((string) $rawLine);
            if ($line === '') {
                $previousType = '';
                continue;
            }

            if ($this->isEpisode($line)) {
                $blocks[] = ['type' => 'episode', 'text' => $line];
                $previousType = 'episode';
                continue;
            }
            if ($this->isSceneHeading($line)) {
                $blocks[] = ['type' => 'scene', 'text' => $line];
                $previousType = 'scene';
                continue;
            }
            if ($this->isTransition($line)) {
                $type = preg_match('/^(THE END|剧终|完)$/iu', trim($line)) === 1 ? 'end' : 'transition';
                $blocks[] = ['type' => $type, 'text' => $line];
                $previousType = $type;
                continue;
            }

            $inlineDialogue = $this->splitInlineDialogue($line);
            if ($inlineDialogue !== null) {
                $blocks[] = ['type' => 'character', 'text' => $inlineDialogue[0]];
                $blocks[] = ['type' => 'dialogue', 'text' => $inlineDialogue[1]];
                $previousType = 'dialogue';
                continue;
            }

            if ($this->isParenthetical($line) && in_array($previousType, ['character', 'parenthetical', 'dialogue'], true)) {
                $blocks[] = ['type' => 'parenthetical', 'text' => $line];
                $previousType = 'parenthetical';
                continue;
            }

            if ($this->isCharacter($line, $lines, $index)) {
                $blocks[] = ['type' => 'character', 'text' => $line];
                $previousType = 'character';
                continue;
            }

            if (in_array($previousType, ['character', 'parenthetical', 'dialogue'], true)) {
                $blocks[] = ['type' => 'dialogue', 'text' => $line];
                $previousType = 'dialogue';
                continue;
            }

            $blocks[] = ['type' => 'action', 'text' => $line];
            $previousType = 'action';
        }

        return $blocks;
    }

    private function renderBlocks(array $blocks): string
    {
        $html = '';
        $dialogueOpen = false;
        foreach ($blocks as $block) {
            $type = (string) ($block['type'] ?? 'action');
            $isDialoguePart = in_array($type, ['character', 'parenthetical', 'dialogue'], true);
            if ($isDialoguePart && !$dialogueOpen) {
                $html .= '<section class="dialogue-unit">';
                $dialogueOpen = true;
            } elseif (!$isDialoguePart && $dialogueOpen) {
                $html .= '</section>';
                $dialogueOpen = false;
            }

            $html .= '<div class="block ' . $type . '">' . $this->escape((string) ($block['text'] ?? '')) . '</div>';
        }
        if ($dialogueOpen) {
            $html .= '</section>';
        }
        return $html;
    }

    private function cleanLine(string $line): string
    {
        $line = trim($line);
        $line = preg_replace('/^#{1,6}\s*/u', '', $line) ?? $line;
        $line = preg_replace('/^(?:[-*+]\s+|\d+[.)]\s+)/u', '', $line) ?? $line;
        $line = preg_replace('/\*\*(.*?)\*\*/u', '$1', $line) ?? $line;
        $line = preg_replace('/__(.*?)__/u', '$1', $line) ?? $line;
        return trim($line, " \t\n\r\0\x0B`");
    }

    private function isEpisode(string $line): bool
    {
        return preg_match('/^(?:EPISODE\s+[A-Z0-9一二三四五六七八九十百]+|第\s*[0-9一二三四五六七八九十百]+\s*集)(?:\b|\s|[:：.-])/iu', $line) === 1;
    }

    private function isSceneHeading(string $line): bool
    {
        return preg_match('/^(?:INT\.?|EXT\.?|INT\.?\/EXT\.?|I\/E\.?|内景|外景|内\/外景|场景\s*\d+|第\s*\d+\s*场)\b/iu', $line) === 1
            || preg_match('/^(?:场景|SCENE)\s*[0-9一二三四五六七八九十百]+\s*[:：.-]/iu', $line) === 1;
    }

    private function isTransition(string $line): bool
    {
        $line = trim($line);
        return preg_match('/^(?:FADE IN:?|FADE OUT\.?|CUT TO:?|DISSOLVE TO:?|SMASH CUT TO:?|MATCH CUT TO:?|淡入[:：]?|淡出[:：]?|切至[:：]?|转场[:：]?|THE END|剧终|完)$/iu', $line) === 1;
    }

    private function isParenthetical(string $line): bool
    {
        return preg_match('/^[（(].{1,160}[）)]$/u', $line) === 1;
    }

    private function splitInlineDialogue(string $line): ?array
    {
        if (preg_match('/^([\p{Han}A-Za-z0-9][\p{Han}A-Za-z0-9 .·\'’()（）-]{0,28})[：:]\s*(\S.+)$/u', $line, $matches) !== 1) {
            return null;
        }
        $speaker = trim((string) $matches[1]);
        if ($this->isSceneHeading($speaker . ':') || in_array($speaker, ['场景', '时间', '地点', '备注'], true)) {
            return null;
        }
        return [$speaker, trim((string) $matches[2])];
    }

    private function isCharacter(string $line, array $allLines, int $index): bool
    {
        $length = mb_strlen($line, 'UTF-8');
        if ($length < 1 || $length > 45 || preg_match('/[。！？!?：:，,；;]$/u', $line) === 1) {
            return false;
        }

        $hasLetter = preg_match('/[\p{L}\p{Han}]/u', $line) === 1;
        $isUppercase = $hasLetter && mb_strtoupper($line, 'UTF-8') === $line && mb_strtolower($line, 'UTF-8') !== $line;
        if ($isUppercase) {
            return true;
        }

        if (preg_match('/^[\p{Han}·]{2,8}(?:\s*[（(][^）)]{1,30}[）)])?$/u', $line) !== 1) {
            return false;
        }

        for ($next = $index + 1, $count = count($allLines); $next < $count; $next++) {
            $candidate = $this->cleanLine((string) $allLines[$next]);
            if ($candidate === '') {
                continue;
            }
            return !$this->isEpisode($candidate) && !$this->isSceneHeading($candidate) && !$this->isTransition($candidate);
        }
        return false;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function tempDirectory(): string
    {
        $ownerSuffix = function_exists('posix_geteuid') ? (string) posix_geteuid() : substr(md5(__DIR__), 0, 8);
        $directory = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'malulu-script-pdf-' . $ownerSuffix;
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }
        if (!is_writable($directory)) {
            $directory .= '-' . bin2hex(random_bytes(4));
            mkdir($directory, 0777, true);
        }
        return $directory;
    }
}
