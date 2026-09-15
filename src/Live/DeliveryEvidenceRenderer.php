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
}
