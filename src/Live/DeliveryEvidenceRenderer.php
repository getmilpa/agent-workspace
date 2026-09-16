<?php

/**
 * This file is part of Milpa Agent Workspace.
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency
 *
 * @license Apache-2.0
 */

declare(strict_types=1);

namespace Milpa\AgentWorkspace\Live;

use Milpa\AgentWorkspace\Admin\PanelSession;
use Milpa\AgentWorkspace\I18n\Catalog;
use Milpa\Live\Contracts\Component\ComponentDefinitionInterface;
use Milpa\Live\Contracts\Rendering\{ComponentRendererInterface,DeclaresClientAssets};
use Milpa\Live\ValueObjects\{ClientAssets,RenderRequest,RenderResult,RenderTarget};

/** Read on every render from authenticated context, containing failures without stale evidence. */
final class DeliveryEvidenceRenderer implements ComponentRendererInterface, DeclaresClientAssets
{
    /** @param \Closure(string): array<string, mixed>|null $read Trusted server reader, never a client prop. */
    public function __construct(private readonly ?\Closure $read = null, private readonly Catalog $catalog = new Catalog())
    {
    }
    /** Evidence is a web surface. */
    public function supportsTarget(RenderTarget $target): bool
    {
        return $target === RenderTarget::HTML;
    }
    /** Only this component's stylesheet. */
    public function clientAssets(): ClientAssets
    {
        return DesktopAssets::of(DeliveryEvidenceComponent::NAME);
    }
    /** Ignore supplied props/state; evidence and identity are re-read at the server boundary. */
    public function render(ComponentDefinitionInterface $component, RenderRequest $request): RenderResult
    {
        if ($component::contract()->name !== DeliveryEvidenceComponent::NAME || !$this->supportsTarget($request->target)) {
            throw new \InvalidArgumentException('Delivery evidence requires its HTML component.');
        }
        $session = PanelSession::forPrincipal($request->context->principal);
        $sample = ['session' => $session, 'state' => 'unavailable', 'report' => null];
        try {
            if ($this->read !== null) {
                $sample = ($this->read)($session);
                if (($sample['session'] ?? null) !== $session) {
                    throw new \DomainException('Evidence belongs to another session.');
                }
            }
            $html = $this->paint($sample, $request->context->componentId);
        } catch (\Throwable) {
            $html = $this->paint(['state' => 'read_failed', 'report' => null], $request->context->componentId);
        }
        return new RenderResult(output: $html, state: $component->mount([], $request->context), clientAssets: $this->clientAssets());
    }
    /** @param array<string, mixed> $sample Server observations; never serialized into client state. */
    private function paint(array $sample, string $id): string
    {
        $t = fn (string $key): string => $this->catalog->tr('evidence.' . $key);
        $escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $report = $sample['report'];
        $state = $report['evidenceState'] ?? $sample['state'];
        $html = '<section class="mui-card desktop-delivery-evidence" data-native-evidence="' . $escape($state) . '" aria-labelledby="' . $escape($id) . '-title">'
            . '<h2 id="' . $escape($id) . '-title">' . $escape($t('title')) . '</h2>'
            . '<p data-evidence-status>' . $escape($t($state)) . '</p>';
        $html .= $this->criteria($sample, $id);
        if (($sample['termination']['reason'] ?? null) === 'output_truncated') {
            $html .= '<p data-explanation-status>' . $escape($t('truncated')) . '</p>';
        }
        if ($report !== null) {
            $html .= '<dl>';
            $values = ['artifact' => $sample['declaration']['scope']['artifactPath'], 'tests' => $report['test']['tests'],
                'failures' => $report['test']['failures'], 'errors' => $report['test']['errors'],
                'ran' => $report['test']['ran'], 'coverage' => $report['test']['coversRequestedScope'],
                'approval' => $t($report['humanApproval'])];
            foreach ($values as $key => $value) {
                $text = $value === null ? $t('unknown') : (is_bool($value) ? $t($value ? 'yes' : 'no') : (string) $value);
                $html .= '<dt>' . $escape($t($key)) . '</dt><dd data-evidence-field="' . $escape($key) . '">' . $escape($text) . '</dd>';
            }
            $html .= '</dl>';
            if (is_string($report['screen']['reviewUrl'])) {
                $html .= '<p><a class="mui-btn mui-btn--primary" data-evidence-review href="' . $escape($report['screen']['reviewUrl']) . '">' . $escape($t('review')) . '</a></p>';
            }
        }
        $html .= '<p>' . $escape($t('reload')) . '</p>';
        return $html . '</section>';
    }

    /** Render durable criteria and a pending next-message choice without declaring client-side facts.
     * @param array<string,mixed> $sample The native reader's current sample.
     */
    private function criteria(array $sample, string $id): string
    {
        $e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $t = fn (string $key): string => $e($this->catalog->tr('evidence.' . $key));
        $html = '';
        $expected = $sample['expectation']['expected'] ?? null;
        if ($expected !== null) {
            $html .= '<div data-recorded-expectation><h3>' . $t('criteria') . '</h3><dl>';
            foreach (['test_path' => $expected['test']['path'], 'test_filter' => $expected['test']['filter'] === '' ? $this->catalog->tr('evidence.all_tests') : $expected['test']['filter'],
                'screen_name' => $expected['screen']['name'], 'screen_type' => $expected['screen']['type']] as $key => $value) {
                $html .= '<dt>' . $t($key) . '</dt><dd data-expected-field="' . $e($key) . '">' . $e($value) . '</dd>';
            }
            $html .= '</dl>';
            if (isset($expected['screen']['definition'])) {
                $html .= '<details><summary>' . $t('screen_definition') . '</summary><pre data-expected-definition>'
                    . $e(json_encode($expected['screen']['definition'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) . '</pre></details>';
            }
            $html .= '<p>' . $t('criteria_fixed') . '</p></div>';
        } elseif (($sample['canDeclareExpectation'] ?? false) === true) {
            $html .= '<details data-expectation-editor><summary>' . $t('set_criteria') . '</summary><p>' . $t('criteria_help') . '</p>'
                . '<label class="delivery-choice"><input type="checkbox" data-expectation-enable> ' . $t('record_next') . '</label>'
                . '<fieldset data-expectation-fields disabled><legend>' . $t('criteria_form') . '</legend>';
            foreach (['test_path', 'test_filter', 'screen_name', 'screen_type', 'screen_definition'] as $key) {
                $fieldId = $e($id . '-' . $key);
                $html .= '<label for="' . $fieldId . '">' . $t($key) . '</label>';
                $html .= $key === 'screen_definition'
                    ? '<textarea id="' . $fieldId . '" data-expectation-field="' . $key . '" rows="5" spellcheck="false"></textarea>'
                    : '<input id="' . $fieldId . '" data-expectation-field="' . $key . '" type="text" autocomplete="off">';
            }
            $html .= '</fieldset></details>';
        }
        $selection = $sample['candidateSelection'] ?? null;
        if ($expected !== null && $selection !== null && ($sample['declaration'] ?? null) === null) {
            $html .= '<div data-candidate-selection><h3>' . $t('candidate') . '</h3><p><code>' . $e($selection['scope']['artifactPath']) . '</code></p>'
                . '<label class="delivery-choice"><input type="checkbox" data-delivery-candidate value="' . $e($selection['scope']['workspace']) . '"> ' . $t('select_next') . '</label>'
                . '<p>' . $t('selection_help') . '</p></div>';
        } elseif ($expected !== null && isset($sample['declaration']['binding'])) {
            $html .= '<p data-candidate-bound>' . $t('selected') . ' <code>' . $e($sample['declaration']['scope']['artifactPath']) . '</code></p>';
        }
        return $html;
    }
}
