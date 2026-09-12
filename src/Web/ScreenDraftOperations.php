<?php

/** Apache-2.0 · (c) Rodrigo Vicente - TeamX Agency */
declare(strict_types=1);

namespace Milpa\AppRuntime\Web;

use Milpa\Command\{CommandProvider,Operation};
use Milpa\Command\Effect\{EffectProfile,Mutation,Externality,Reversibility,Subject,Authority};

/** CLI, MCP and the resident agent name the same immutable revisions as the review UI. */
final readonly class ScreenDraftOperations implements CommandProvider
{
    public function __construct(private ScreenDrafts $drafts, private string $route = '/live')
    {
    }
    /**
     * Revision operations with the same scopes enforced by the review component.
     *
     * @return list<Operation>
     */
    public function operations(): array
    {
        $out = [];
        foreach (['draft','review','promote','rollback'] as $verb) {
            $write = $verb !== 'review';
            $out[] = new Operation(
                name:'screen:' . $verb,
                description:match($verb) {
                    'draft' => 'Create an immutable screen proposal without changing the active screen or application data. Return its reviewAt link to the human.','review' => 'Read screen drafts, or inspect an exact revision, its active baseline and reviewAt link.','promote' => 'Activate exactly the named draft if its baseline and app build remain current. Application data is not copied.','rollback' => 'Restore a draft’s baseline only while its proposed declaration is active; application data is preserved.'
                },
                handler:fn (array $input): array => $this->call($verb, $input),
                inputSchema:['type' => 'object','required' => $verb === 'draft' ? ['name','type','props'] : ($verb === 'review' ? [] : ['revision']),
                    'properties' => $verb === 'draft' ? ['name' => ['type' => 'string'],'type' => ['type' => 'string','x-milpa-source' => ['tool' => 'screen:types','path' => 'types','key' => 'name']],'props' => ['type' => 'object']] : ['revision' => ['type' => 'string','description' => 'Immutable revision id returned by screen:draft or screen:review.']]],
                mutating:$write,
                namedTarget:$verb === 'review' ? null : ($verb === 'draft' ? 'name' : 'revision'),
                scopes:['milpa:component:screen-review:' . ($verb === 'review' ? 'read' : $verb)],
                effects:$write ? new EffectProfile(Mutation::Persistent, Externality::None, Reversibility::Compensatable, Authority::WriteAsUser, subject:Subject::Data) : EffectProfile::readOnly()
            );
        }
        return $out;
    }
    /**
     * @param array<string,mixed> $input
     *
     * @return array<string,mixed>
     */
    private function call(string $verb, array $input): array
    {
        try {
            $id = is_string($input['revision'] ?? null) ? $input['revision'] : '';
            $data = match($verb) {
                'draft' => $this->drafts->draft(is_string($input['name'] ?? null) ? $input['name'] : '', is_string($input['type'] ?? null) ? $input['type'] : '', is_array($input['props'] ?? null) ? $input['props'] : []),
                'review' => $id === '' ? $this->drafts->catalogue() : $this->drafts->review($id),
                'promote' => $this->drafts->promote($id),
                'rollback' => $this->drafts->rollback($id),
                default => throw new \LogicException('Unknown screen operation'),
            };
            if (is_string($data['id'] ?? null)) {
                $data['reviewAt'] = $this->route . '/review?revision=' . $data['id'];
                $data['previewAt'] = $this->route . '/preview?revision=' . $data['id'];
            }
            return ['ok' => true,'result' => $data];
        } catch (\DomainException|InvalidScreenTree $e) {
            return ['ok' => false,'error' => $e->getMessage()];
        }
    }
}
