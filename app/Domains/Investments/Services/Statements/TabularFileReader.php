<?php

namespace App\Domains\Investments\Services\Statements;

use RuntimeException;
use XMLReader;
use ZipArchive;

/** Lê CSV e XLSX (primeira planilha) sem dependências externas. Retorna cabeçalho + linhas como strings. */
class TabularFileReader
{
    public const MAX_ROWS = 20000;

    private bool $rawMode = false;

    /** Todas as linhas não vazias, sem escolher cabeçalho (leitor genérico decide). @return list<list<string>> */
    public function rawRows(string $path, string $kind): array
    {
        $this->rawMode = true;
        try {
            return $this->read($path, $kind)['rows'];
        } finally {
            $this->rawMode = false;
        }
    }

    /** @return array{headers: list<string>, rows: list<list<string>>} */
    public function read(string $path, string $kind): array
    {
        return match ($kind) {
            'csv' => $this->csv((string) file_get_contents($path)),
            'xlsx' => $this->xlsx($path),
            default => throw new RuntimeException('Formato tabular não suportado: '.$kind),
        };
    }

    /** @return array{headers: list<string>, rows: list<list<string>>} */
    public function csv(string $content): array
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        if (! mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }
        $lines = preg_split('/\r\n|\n|\r/', trim($content));
        $first = $lines[0] ?? '';
        $delims = [';' => substr_count($first, ';'), ',' => substr_count($first, ','), "\t" => substr_count($first, "\t")];
        arsort($delims);
        $d = array_key_first($delims);
        $rows = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $rows[] = array_map(fn ($v) => trim((string) $v), str_getcsv($line, $d, '"', ''));
            if (count($rows) > self::MAX_ROWS) {
                throw new RuntimeException('Arquivo com mais de '.self::MAX_ROWS.' linhas.');
            }
        }

        return $this->split($rows);
    }

    /** @return array{headers: list<string>, rows: list<list<string>>} */
    public function xlsx(string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException('XLSX inválido ou corrompido.');
        }
        $shared = [];
        if (($xml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
            $r = new XMLReader;
            $r->XML($xml, null, LIBXML_NONET);
            $cur = null;
            while ($r->read()) {
                if ($r->nodeType === XMLReader::ELEMENT && $r->localName === 'si') {
                    $cur = '';
                } elseif ($r->nodeType === XMLReader::ELEMENT && $r->localName === 't' && $cur !== null) {
                    $cur .= $r->readString();
                } elseif ($r->nodeType === XMLReader::END_ELEMENT && $r->localName === 'si') {
                    $shared[] = $cur;
                    $cur = null;
                }
            }
        }
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        if ($sheet === false) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $n = $zip->getNameIndex($i);
                if (str_starts_with($n, 'xl/worksheets/') && str_ends_with($n, '.xml')) {
                    $sheet = $zip->getFromName($n);
                    break;
                }
            }
        }
        $zip->close();
        if ($sheet === false) {
            throw new RuntimeException('XLSX sem planilha.');
        }
        $r = new XMLReader;
        $r->XML($sheet, null, LIBXML_NONET);
        $rows = [];
        $row = null;
        $col = 0;
        $type = null;
        while ($r->read()) {
            if ($r->nodeType === XMLReader::ELEMENT && $r->localName === 'row') {
                $row = [];
            } elseif ($r->nodeType === XMLReader::ELEMENT && $r->localName === 'c') {
                $col = $this->colIndex((string) $r->getAttribute('r'), count($row ?? []));
                $type = $r->getAttribute('t');
                if ($r->isEmptyElement) {
                    $row[$col] = '';
                }
            } elseif ($r->nodeType === XMLReader::ELEMENT && ($r->localName === 'v' || ($r->localName === 't' && $type === 'inlineStr'))) {
                $v = $r->readString();
                $row[$col] = $type === 's' ? ($shared[(int) $v] ?? '') : $v;
            } elseif ($r->nodeType === XMLReader::END_ELEMENT && $r->localName === 'row') {
                if ($row) {
                    $max = max(array_keys($row));
                    $filled = [];
                    for ($i = 0; $i <= $max; $i++) {
                        $filled[] = trim((string) ($row[$i] ?? ''));
                    }
                    if (implode('', $filled) !== '') {
                        $rows[] = $filled;
                    }
                }
                $row = null;
                if (count($rows) > self::MAX_ROWS) {
                    throw new RuntimeException('Arquivo com mais de '.self::MAX_ROWS.' linhas.');
                }
            }
        }

        return $this->split($rows);
    }

    private function colIndex(string $ref, int $fallback): int
    {
        if (! preg_match('/^([A-Z]+)/', $ref, $m)) {
            return $fallback;
        }
        $n = 0;
        foreach (str_split($m[1]) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }

        return $n - 1;
    }

    /**
     * Cabeçalho: a linha (entre as 80 primeiras) com mais nomes de coluna conhecidos (data, valor, descrição, saldo…);
     * sem nenhuma, a primeira linha com ≥ 3 células preenchidas (extratos costumam ter título antes).
     */
    private function split(array $rows): array
    {
        if ($this->rawMode) {
            return ['headers' => [], 'rows' => array_values($rows)];
        }
        $bestIndex = null;
        $bestScore = 1;
        foreach (array_slice($rows, 0, 80, true) as $i => $r) {
            $score = 0;
            foreach ($r as $cell) {
                $c = mb_strtolower(trim((string) $cell));
                if ($c !== '' && mb_strlen($c) <= 40 && preg_match('/^(data|dt\b|movimenta|liquida|descri|hist[oó]r|lan[cç]amento|valor|saldo|ativo|ticker|produto|quantidade|qtd|pre[cç]o|tipo|evento|cr[eé]dito|d[eé]bito|entrada|sa[ií]da)/u', $c)) {
                    $score++;
                }
            }
            if ($score > $bestScore && count(array_filter($r, fn ($v) => $v !== '')) >= 3) {
                $bestScore = $score;
                $bestIndex = $i;
            }
        }
        if ($bestIndex !== null) {
            $r = $rows[$bestIndex];
            $headers = array_map(fn ($h, $k) => $h !== '' ? $h : 'Coluna '.($k + 1), $r, array_keys($r));

            return ['headers' => $headers, 'rows' => array_values(array_slice($rows, $bestIndex + 1))];
        }
        foreach ($rows as $i => $r) {
            if (count(array_filter($r, fn ($v) => $v !== '')) >= 3) {
                $headers = array_map(fn ($h, $k) => $h !== '' ? $h : 'Coluna '.($k + 1), $r, array_keys($r));
                $data = array_values(array_slice($rows, $i + 1));

                return ['headers' => $headers, 'rows' => $data];
            }
        }
        throw new RuntimeException('Não encontrei cabeçalho (linha com ao menos 3 colunas).');
    }
}
