<?php

/** This file is part of Milpa Agent Workspace.
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency
 *
 * @license Apache-2.0
 */
declare(strict_types=1);

namespace Milpa\AgentWorkspace\Tests\Live;

use Milpa\AgentWorkspace\Admin\PanelSession;
use Milpa\AgentWorkspace\Data\FactualReport;
use Milpa\AgentWorkspace\I18n\Catalog;
use Milpa\AgentWorkspace\Live\{DeliveryEvidenceComponent,DeliveryEvidenceRenderer,SessionStripComponent};
use Milpa\Live\ValueObjects\{ComponentContext,RenderRequest};
use PHPUnit\Framework\TestCase;

/** Native report semantics and authenticated rendering, based on measured receipts. */
final class DeliveryEvidenceTest extends TestCase
{
    /** @return array<string, mixed> */
    private function fixtures(): array
    {
        return json_decode(file_get_contents(__DIR__ . '/../Fixtures/delivery-evidence.json'), true, flags: JSON_THROW_ON_ERROR);
    }
    public function testReportRetainsMeasuredCurrentUnknownAndFalse(): void
    {
        $fixtures = $this->fixtures();
        foreach (['positive','indeterminate'] as $name) {
            $s = $fixtures[$name];
            self::assertSame($s['report'], FactualReport::fromSamples($s['evidence'], $s['review'], $s['evidence']));
        }
        $s = $fixtures['partial'];
        $r = FactualReport::fromSamples($s['before'], $s['review'], $s['after']);
        self::assertFalse($r['test']['coversRequestedScope']);
        self::assertFalse($r['readyForHumanReview']);
        $s = $fixtures['positive'];
        $s['review']['result']['reviewAt'] = '/custom/review?exact=1';
        self::assertSame('/custom/review?exact=1', FactualReport::fromSamples($s['evidence'], $s['review'], $s['evidence'])['screen']['reviewUrl']);
        $s['evidence']['screen']['revision'] = null;
        self::assertNull(FactualReport::fromSamples($s['evidence'], null, $s['evidence'])['screen']['reviewUrl']);
    }
    public function testContradictorySamplesAndWrongReviewsAreRefused(): void
    {
        foreach (['changed','failed','revision','url'] as $kind) {
            $s = $this->fixtures()['positive'];
            $after = $s['evidence'];
            if ($kind === 'changed') {
                $after['state'] = 'historical_evidence';
            } elseif ($kind === 'failed') {
                $s['review']['ok'] = false;
            } elseif ($kind === 'revision') {
                $s['review']['result']['id'] = 'wrong';
            } else {
                $s['review']['result']['reviewAt'] = null;
            }
            try {
                FactualReport::fromSamples($s['evidence'], $s['review'], $after);
                self::fail('Contradiction accepted');
            } catch (\DomainException $error) {
                self::assertNotSame('', $error->getMessage());
            }
        }
    }
    public function testIdentityComesFromContextAndEveryRenderReadsAgain(): void
    {
        $seen = [];
        $s = $this->fixtures()['positive'];
        $renderer = new DeliveryEvidenceRenderer(function (string $session) use (&$seen, $s): array {
            $seen[] = $session;
            $s['session'] = $session;
            return $s;
        });
        $component = new DeliveryEvidenceComponent();
        foreach (['owner','reader'] as $actor) {
            $request = new RenderRequest(new ComponentContext('evidence', principal:$actor, meta:['query' => ['principal' => 'forged']]), props:['sample' => ['report' => 'approved'],'session' => 'forged']);
            $r = $renderer->render($component, $request);
            self::assertStringContainsString('Ready for human review', $r->output);
            self::assertStringNotContainsString('data-evidence-sample', $r->output);
            self::assertSame([], $r->state->data);
        }
        self::assertSame([PanelSession::forPrincipal('owner'),PanelSession::forPrincipal('reader')], $seen);
        self::assertSame([], DeliveryEvidenceComponent::contract()->actions);
    }
    public function testFailureIsContainedWithoutLeakingAndNextRenderRecovers(): void
    {
        $count = 0;
        $s = $this->fixtures()['positive'];
        $renderer = new DeliveryEvidenceRenderer(function (string $session) use (&$count, $s): array {
            if (++$count === 1) {
                throw new \RuntimeException('/private/secret');
            }$s['session'] = $session;
            return $s;
        });
        $request = new RenderRequest(new ComponentContext('evidence', principal:'owner'));
        $c = new DeliveryEvidenceComponent();
        $r = $renderer->render($c, $request)->output;
        self::assertStringContainsString('Evidence could not be read', $r);
        self::assertStringNotContainsString('/private/secret', $r);
        self::assertStringNotContainsString('data-evidence-review', $r);
        self::assertStringContainsString('Ready for human review', $renderer->render($c, $request)->output);
        $wrong = new DeliveryEvidenceRenderer(fn (string $session): array => ['session' => 'foreign','report' => null]);
        self::assertStringContainsString('Evidence could not be read', $wrong->render($c, $request)->output);
        self::assertStringContainsString('not available in this app', (new DeliveryEvidenceRenderer())->render($c, $request)->output);
    }
    public function testLocalesEscapingAndUnknownValues(): void
    {
        foreach (['en','es'] as $locale) {
            foreach (['missing','indeterminate','positive'] as $name) {
                $s = $this->fixtures()[$name];
                if ($name === 'positive') {
                    $s['declaration']['scope']['artifactPath'] = '<img src=x onerror=alert(1)>';
                }
                $renderer = new DeliveryEvidenceRenderer(function (string $id) use ($s): array {
                    $s['session'] = $id;
                    return $s;
                }, new Catalog($locale));
                $html = $renderer->render(new DeliveryEvidenceComponent(), new RenderRequest(new ComponentContext('custom-id')))->output;
                self::assertStringContainsString('custom-id-title', $html);
                self::assertStringNotContainsString('<img', $html);
                if ($name === 'indeterminate') {
                    self::assertStringContainsString($locale === 'en' ? 'Unknown' : 'Desconocido', $html);
                }
                if ($name === 'missing') {
                    self::assertStringNotContainsString('data-evidence-review', $html);
                }
            }
        }
    }
    public function testOptionalRuntimeAndAgentRemainOptional(): void
    {
        foreach (['runtime', 'old', 'agent'] as $mode) {
            $lines = [];
            $exit = 1;
            exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../Fixtures/delivery-without-runtime.php') . ' ' . escapeshellarg($mode) . ' 2>&1', $lines, $exit);
            self::assertSame(0, $exit, implode("\n", $lines));
            $r = json_decode(end($lines), true, flags:JSON_THROW_ON_ERROR);
            self::assertTrue($r['missing']);
            self::assertSame('unavailable', $r['sample']['state']);
            self::assertNull($r['sample']['report']);
        }
    }
    public function testOtherComponentsAreRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new DeliveryEvidenceRenderer())->render(new SessionStripComponent(), new RenderRequest(new ComponentContext('wrong')));
    }
}
