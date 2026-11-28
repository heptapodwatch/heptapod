<?php

declare(strict_types=1);

namespace Heptapod\Contracts;

/**
 * The part of docs/openapi.json that an addon owns: the text Scramble cannot infer for the operations of its routes/api.php. Each
 * map is keyed like its ApiContract or ApiExamples namesake and joins it after core's entries; a key core already has
 * fails the export. The addon provider tags it: `$this->app->tag([...], ApiDocumentPart::class)`.
 */
interface ApiDocumentPart
{
    /**
     * Whether GET /api/openapi.json lists this part. Off, the served document drops its tags with their operations, its
     * components and component properties, its token note, and the ability lines that name only its operations.
     */
    public function served(): bool;

    /** @return array<string, string> tag name => description; the addon's API controllers join one with #[Group] */
    public function tags(): array;

    /** @return array<string, array<string, mixed>> */
    public function components(): array;

    /** @return array<string, array<string, array<string, mixed>>> optional properties the addon adds to a core component override, by component */
    public function componentProperties(): array;

    /** Its own line of the bearer scheme description, after core's notes on the abilities. */
    public function tokenNote(): string;

    /** @return array<string, array<string, mixed>> */
    public function requestBodies(): array;

    /** @return array<string, array<int, array<string, mixed>>> */
    public function responses(): array;

    /** @return array<string, string> */
    public function operationDescriptions(): array;

    /** @return array<string, string> */
    public function parameterDescriptions(): array;

    /** @return array<string, mixed> */
    public function requestExamples(): array;

    /** @return array<string, array<int, mixed>> */
    public function responseExamples(): array;
}
