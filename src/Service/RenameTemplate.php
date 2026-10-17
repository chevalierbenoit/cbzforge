<?php

namespace App\Service;

use App\Model\ComicInfo;

class RenameTemplate
{
    private const ALIASES = [
        'serie' => 'series',
        'titre' => 'title',
        'numero' => 'number',
        'annee' => 'year',
        'scenariste' => 'writer',
        'dessinateur' => 'artist',
        'dessin' => 'penciler',
        'encreur' => 'inker',
        'coloriste' => 'colorist',
        'lettreur' => 'letterer',
        'couverture' => 'coverArtist',
        'editeur' => 'publisher',
        'resume' => 'summary',
        'isbn' => 'isbn',
        'classification' => 'ageRating',
        'langue' => 'languageIso',
        'genre' => 'genre',
        'personnages' => 'characters',
    ];

    public function render(string $expression, ComicInfo $info): string
    {
        $result = $this->substitute($expression, $info);
        $result = $this->sanitize($result);
        $result = $this->cleanup($result);
        $result = $this->stripDotDot($result);
        $result = trim($result);

        if ('' === $result) {
            return $this->fallback($info);
        }

        return $result;
    }

    public function preview(string $expression): string
    {
        $demo = new ComicInfo(
            series: 'Jérôme K. Jérôme Bloche',
            number: 14,
            title: 'Un fauve en cage',
            year: 2024,
            writer: 'Makyo',
            artist: 'Dodier',
            publisher: 'Dupuis',
            languageIso: 'fr',
            genre: ['Policier', 'Drame'],
        );

        return $this->render($expression, $demo);
    }

    /**
     * @return array<int, array{alias: string, field: string, description: string}>
     */
    public function getAvailableAliases(): array
    {
        /** @var array<string, string> $descriptions */
        $descriptions = [
            'serie' => 'Nom de la série',
            'titre' => "Titre de l'album",
            'numero' => 'Numéro du tome',
            'annee' => 'Année de publication',
            'scenariste' => 'Scénariste',
            'dessinateur' => 'Dessinateur',
            'dessin' => 'Dessinateur (crayonné)',
            'encreur' => 'Encreur',
            'coloriste' => 'Coloriste',
            'lettreur' => 'Lettreur',
            'couverture' => 'Dessinateur de couverture',
            'editeur' => 'Éditeur',
            'resume' => 'Résumé',
            'isbn' => 'ISBN',
            'classification' => 'Classification (âge)',
            'langue' => 'Langue (code ISO)',
            'genre' => 'Genre(s)',
            'personnages' => 'Personnage(s)',
        ];

        $result = [];
        foreach (self::ALIASES as $alias => $field) {
            $result[] = [
                'alias' => '{'.$alias.'}',
                'field' => $field,
                'description' => $descriptions[$alias] ?? $field,
            ];
        }

        return $result;
    }

    private function substitute(string $expression, ComicInfo $info): string
    {
        return preg_replace_callback('/\{(\w+)\}/', static function ($m) use ($info) {
            $alias = $m[1];
            $field = self::ALIASES[$alias] ?? null;

            if (null === $field) {
                return $m[0];
            }

            $value = match ($field) {
                'genre', 'characters' => $info->{$field}
                    ? implode(', ', $info->{$field})
                    : null,
                default => $info->{$field} ?? null,
            };

            return null !== $value ? (string) $value : '';
        }, $expression);
    }

    private function sanitize(string $path): string
    {
        $segments = explode('/', $path);
        $segments = array_map(static function (string $segment): string {
            $segment = preg_replace('/[\\\\:*?"<>|\x00-\x1f]/', '-', $segment);
            $segment = preg_replace('/-+/', '-', $segment);
            $segment = trim($segment);

            return $segment;
        }, $segments);

        return implode('/', $segments);
    }

    private function cleanup(string $path): string
    {
        $path = preg_replace('/\s+/', ' ', $path);
        $path = preg_replace('/\s*\(\s*\)/', '', $path);
        $path = preg_replace('/\s*\[\s*\]/', '', $path);
        $path = preg_replace('/^\s*[-\s,_;:.]+\s*/', '', $path);
        $path = preg_replace('/[-\s,_;:.]+$/', '', $path);
        $path = preg_replace('/-{2,}/', '-', $path);
        $path = preg_replace('/_{2,}/', '_', $path);
        $path = preg_replace('/\/{2,}/', '/', $path);
        $segments = array_map('trim', explode('/', $path));
        $segments = array_filter($segments, static fn (string $s) => '' !== $s);
        $path = implode('/', $segments);
        $path = preg_replace('/\s+/', ' ', $path);

        return trim($path);
    }

    private function stripDotDot(string $path): string
    {
        $segments = array_filter(explode('/', $path), static fn (string $s) => '..' !== trim($s));

        return implode('/', $segments);
    }

    private function fallback(ComicInfo $info): string
    {
        if (null !== $info->series && '' !== $info->series) {
            return $this->sanitize($info->series);
        }
        if (null !== $info->title && '' !== $info->title) {
            return $this->sanitize($info->title);
        }

        return 'inconnu';
    }
}
