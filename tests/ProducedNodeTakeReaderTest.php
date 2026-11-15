<?php

namespace Splicewire\Beam\Media\Tests;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\ModelStatus\Status;
use Splicewire\Beam\Media\Models\ProviderMediaJob;
use Splicewire\Beam\Media\Takes\ProducedNodeTakeReader;

class ProducedNodeTakeReaderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['model-status.status_model' => Status::class]);
        Schema::create('statuses', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('reason')->nullable();
            $table->uuidMorphs('model');
            $table->timestamps();
        });
        $migration = require __DIR__.'/../database/migrations/shared/create_provider_media_jobs_table.php.stub';
        $migration->up();
    }

    public function test_reads_only_the_authorized_produced_node_without_exposing_vendor_urls_or_mutating_jobs(): void
    {
        $node = $this->node();
        $job = $this->job($node);
        DB::table('statuses')->insert(['id' => '019a8000-0000-7000-8000-000000000002', 'name' => 'completed', 'model_type' => $job->getMorphClass(), 'model_id' => $job->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->job($node, ['produced_id' => 'other-node']);
        $this->job($node, ['produced_type' => 'other-kind']);
        $before = $job->fresh()->getAttributes();
        $reader = new ProducedNodeTakeReader($node, fn (Model $subject): bool => $subject === $node);
        $takes = $reader->read((string) $node->getKey())->toArray()['data'];

        $this->assertCount(1, $takes);
        $this->assertSame([
            'id' => $job->id, 'address' => (string) $node->getKey(),
            'medium' => 'music', 'model' => 'music-v1', 'provider' => 'test-provider',
            'status' => 'completed', 'resultUrl' => null, 'seconds' => 12,
            'completedAt' => '2026-11-15T01:00:00.000000Z', 'fingerprint' => null, 'kind' => 'artifact',
        ], $takes[0]);
        $this->assertSame($before, $job->fresh()->getAttributes());
    }

    public function test_uses_the_host_authorized_retained_media_resolver_only_after_authorization(): void
    {
        $node = $this->node();
        $job = $this->job($node);
        $authorized = false;
        $reader = new ProducedNodeTakeReader(
            $node,
            function () use (&$authorized): bool {
                return $authorized = true;
            },
            function (ProviderMediaJob $take) use (&$authorized, $job): ?string {
                $this->assertTrue($authorized);
                $this->assertSame($job->id, $take->id);

                return 'https://retained.example/authorized-fresh-url';
            },
        );
        $this->assertSame('https://retained.example/authorized-fresh-url', $reader->read((string) $node->getKey())->data[0]->resultUrl);
    }

    public function test_denied_subject_is_rejected_before_any_database_or_url_read(): void
    {
        $node = $this->node();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $reader = new ProducedNodeTakeReader($node, fn (): bool => false, function (): ?string {
            $this->fail('Denied readers must not resolve an artifact URL.');
        });
        try {
            $reader->read((string) $node->getKey());
            $this->fail('The host authorization must be enforced.');
        } catch (AuthorizationException) {
            $this->assertSame([], DB::getQueryLog());
        }
    }

    public function test_an_address_cannot_select_another_subject(): void
    {
        $reader = new ProducedNodeTakeReader($this->node(), fn (): bool => true);
        $this->expectException(AuthorizationException::class);
        $reader->read('another-node');
    }

    public function test_uses_the_bound_nodes_connection_instead_of_the_default_database(): void
    {
        config(['database.connections.take_node' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        foreach (['provider_media_jobs', 'statuses'] as $table) {
            $ddl = DB::selectOne('SELECT sql FROM sqlite_master WHERE type = ? AND name = ?', ['table', $table])->sql;
            DB::connection('take_node')->statement($ddl);
        }
        $node = $this->node();
        $defaultJob = $this->job($node);
        $node->setConnection('take_node');
        $job = new ProviderMediaJob;
        $job->setConnection('take_node');
        $job->fill(['produced_type' => $node->getMorphClass(), 'produced_id' => $node->getKey(),
            'medium' => 'video', 'model' => 'video-v1', 'provider' => 'test-provider']);
        $job->save();
        $takes = (new ProducedNodeTakeReader($node, fn (): bool => true))->read((string) $node->getKey())->data;
        $this->assertCount(1, $takes);
        $this->assertSame($job->id, $takes[0]->id);
        $this->assertNotSame($defaultJob->id, $takes[0]->id);
        DB::purge('take_node');
    }

    private function node(): Model
    {
        $node = new class extends Model
        {
            protected $keyType = 'string';

            public function getMorphClass(): string
            {
                return 'take-test-node';
            }
        };
        $node->setRawAttributes(['id' => '019a8000-0000-7000-8000-000000000001']);
        $node->exists = true;

        return $node;
    }

    private function job(Model $node, array $override = []): ProviderMediaJob
    {
        return ProviderMediaJob::create(array_replace([
            'produced_type' => $node->getMorphClass(), 'produced_id' => $node->getKey(),
            'medium' => 'music', 'model' => 'music-v1', 'provider' => 'test-provider',
            'result_url' => 'https://vendor.example/expired-secret', 'seconds' => 12,
            'completed_at' => '2026-11-15 01:00:00',
        ], $override));
    }
}
