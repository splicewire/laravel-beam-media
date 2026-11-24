<?php

namespace Splicewire\Beam\Media\Tests;

use Splicewire\Beam\Config\ConfigSectionCoverage;
use Splicewire\Beam\Config\ConfigSectionRegistry;
use Splicewire\Beam\Media\Config\BeamMediaSection;

/**
 * The `beam.media` Config Section (ADR-0245) is declared by this package's provider and every key
 * it carries is covered, with no draft copy left on it.
 */
class ConfigSectionCoverageTest extends TestCase
{
    public function test_it_registers_the_beam_media_config_section_from_the_provider(): void
    {
        $registered = app(ConfigSectionRegistry::class)->get('beam.media');

        $this->assertNotNull($registered);
        $this->assertSame(BeamMediaSection::class, $registered->section);
        $this->assertSame('splicewire/laravel-beam-media', $registered->owner);
    }

    public function test_it_covers_every_beam_media_key_and_carries_no_draft_copy(): void
    {
        $this->assertSame([], ConfigSectionCoverage::uncovered(BeamMediaSection::class, config('beam.media')));
        $this->assertSame([], ConfigSectionCoverage::drafts(BeamMediaSection::class));
    }
}
