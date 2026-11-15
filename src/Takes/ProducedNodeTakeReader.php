<?php

namespace Splicewire\Beam\Media\Takes;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Splicewire\Beam\Media\Models\ProviderMediaJob;
use Splicewire\Composition\NodeActions\Takes\ArtifactNodeTakeData;
use Splicewire\Composition\NodeActions\Takes\NodeTakeCollectionData;
use Splicewire\Composition\NodeActions\Takes\NodeTakeReader;

/**
 * A host binds the resolved node and its existing view authority. The address is never a model selector.
 * The optional resolver must use the host's existing authorized retained-media URL mechanism. Without
 * one there is no audition URL; a vendor result_url is provenance and is deliberately never returned.
 */
final class ProducedNodeTakeReader implements NodeTakeReader
{
    /**
     * @param  Closure(Model): bool  $mayView
     * @param  (Closure(ProviderMediaJob): ?string)|null  $resultUrl
     */
    public function __construct(
        private readonly Model $node,
        private readonly Closure $mayView,
        private readonly ?Closure $resultUrl = null,
    ) {}

    public function read(string $address): NodeTakeCollectionData
    {
        if (! $this->node->exists || $address !== (string) $this->node->getKey() || ! ($this->mayView)($this->node)) {
            throw new AuthorizationException;
        }

        $job = new ProviderMediaJob;
        $job->setConnection($this->node->getConnectionName());
        $takes = $job->newQuery()
            ->where('produced_type', $this->node->getMorphClass())
            ->where('produced_id', $this->node->getKey())
            ->orderByDesc('created_at')->orderByDesc('id')
            ->get()->map(fn (ProviderMediaJob $job): ArtifactNodeTakeData => new ArtifactNodeTakeData(
                id: (string) $job->getKey(),
                address: $address,
                medium: $job->medium,
                model: $job->model,
                provider: $job->provider,
                status: (string) $job->status,
                resultUrl: $this->resultUrl === null ? null : ($this->resultUrl)($job),
                seconds: $job->seconds,
                completedAt: $job->completed_at?->toISOString(),
                fingerprint: null,
            ))->all();

        return new NodeTakeCollectionData($takes);
    }
}
