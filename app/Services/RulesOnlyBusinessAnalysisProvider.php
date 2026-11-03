<?php

namespace App\Services;

use App\Contracts\BusinessAnalysisProvider;

class RulesOnlyBusinessAnalysisProvider implements BusinessAnalysisProvider
{
    public function analyze(array $observations, array $userContext): array
    {
        $byKind = [];
        foreach ($observations as $observation) {
            $byKind[$observation['kind']][] = $observation;
        }

        $findings = [];
        $this->infer($findings, $byKind, 'pricing', 'business_model', 'Published pricing suggests a direct paid offer.');
        $this->infer($findings, $byKind, 'call_to_action', 'conversion', 'Calls to action indicate an active conversion path.');
        $this->infer($findings, $byKind, 'contact', 'trust', 'Published contact methods provide a customer trust signal.');
        $this->infer($findings, $byKind, 'structured_data', 'seo', 'Structured data may improve machine-readable discovery.');

        if (! isset($byKind['pricing'])) {
            $findings[] = $this->assumption('business_model', 'No public pricing evidence was observed; revenue model and price points remain unknown.');
        }
        if (! isset($byKind['call_to_action'])) {
            $findings[] = $this->assumption('conversion', 'No explicit call to action was observed; the intended conversion event remains unknown.');
        }
        if ($userContext === []) {
            $findings[] = $this->assumption('audience', 'The intended audience was not supplied and cannot be established from the available evidence alone.');
        }

        if (isset($byKind['call_to_action'])) {
            $findings[] = [
                'epistemic_type' => 'inference', 'category' => 'opportunity',
                'statement' => 'Validate the clearest call to action with one bounded conversion experiment; this is a hypothesis, not an income forecast.',
                'confidence' => 'medium', 'evidence_ids' => $this->evidence($byKind['call_to_action']),
                'details' => [
                    'offer' => 'Use the existing call to action as the test offer.',
                    'audience' => 'Current page visitors; audience size and demand are unknown.',
                    'prerequisites' => ['Define one conversion event', 'Use privacy-preserving aggregate measurement'],
                    'first_experiment' => 'Run one copy variant against the current call to action.',
                    'measurement_plan' => 'Compare aggregate completion rate with a predeclared stopping rule.',
                    'effort' => 'small', 'ongoing_cost' => 'unknown',
                    'dependencies' => ['Sufficient consenting traffic'],
                    'risks' => ['Insufficient sample size', 'Selection bias'],
                    'reasons_not_to_pursue' => ['Do not proceed if measurement would collect sensitive visitor data.'],
                ],
            ];
        }

        return $findings;
    }

    /** @param array<string, list<array{id: string, kind: string, statement: string}>> $byKind */
    private function infer(array &$findings, array $byKind, string $kind, string $category, string $statement): void
    {
        if (isset($byKind[$kind])) {
            $findings[] = ['epistemic_type' => 'inference', 'category' => $category, 'statement' => $statement,
                'confidence' => 'medium', 'evidence_ids' => $this->evidence($byKind[$kind]), 'details' => null];
        }
    }

    /** @return array<string, mixed> */
    private function assumption(string $category, string $statement): array
    {
        return ['epistemic_type' => 'assumption', 'category' => $category, 'statement' => $statement,
            'confidence' => 'unknown', 'evidence_ids' => [], 'details' => null];
    }

    /** @param list<array{id: string, kind: string, statement: string}> $observations @return list<string> */
    private function evidence(array $observations): array
    {
        return array_values(array_slice(array_column($observations, 'id'), 0, 5));
    }
}
