<?php

/** Prior criteria and native candidate selection in the authenticated panel.
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency
 *
 * @license Apache-2.0
 */
declare(strict_types=1);

namespace Milpa\AgentWorkspace\Tests\Live;

use Milpa\AgentWorkspace\I18n\Catalog;
use Milpa\AgentWorkspace\Live\{DeliveryEvidenceComponent,DeliveryEvidenceRenderer};
use Milpa\Live\ValueObjects\{ComponentContext,RenderRequest};
use PHPUnit\Framework\TestCase;

final class DeliveryCriteriaTest extends TestCase
{
    /** @param array<string,mixed> $sample */
    private function render(array $sample, string $locale = 'en'): string
    {
        $renderer = new DeliveryEvidenceRenderer(static fn (string $session): array => ['session' => $session] + $sample, new Catalog($locale));
        return $renderer->render(new DeliveryEvidenceComponent(), new RenderRequest(new ComponentContext('delivery', principal: 'owner'), props: ['canDeclareExpectation' => true]))->output;
    }

    /** @return array<string,mixed> */
    private function sample(): array
    {
        return ['state' => 'awaiting_candidate', 'report' => null, 'declaration' => null,
            'expectation' => ['expected' => ['test' => ['path' => 'tests/Owned', 'filter' => ''], 'screen' => ['name' => 'focus', 'type' => 'focus-counter', 'definition' => ['props' => ['goal' => 6]]]]]];
    }

    public function testOnlyTheServerCanOfferAnInitialForm(): void
    {
        $base = ['state' => 'scope_missing', 'report' => null];
        self::assertStringNotContainsString('data-expectation-editor', $this->render($base));
        $html = $this->render($base + ['canDeclareExpectation' => true]);
        self::assertStringContainsString('data-expectation-enable', $html);
        self::assertStringContainsString('data-expectation-fields disabled', $html);
        self::assertStringNotContainsString('data-recorded-expectation', $html);
        self::assertStringNotContainsString('checked', $html);
        self::assertStringContainsString('with my next message', $html);
    }

    public function testRecordedCriteriaRemainReadonlyEvenIfFormFlagIsSupplied(): void
    {
        $sample = $this->sample() + ['canDeclareExpectation' => true];
        foreach (['en', 'es'] as $locale) {
            $html = $this->render($sample, $locale);
            self::assertStringContainsString('data-recorded-expectation', $html);
            self::assertStringContainsString('tests/Owned', $html);
            self::assertStringContainsString($locale === 'en' ? 'All tests in this path' : 'Todas las pruebas de esta ruta', $html);
            self::assertStringContainsString('data-expected-definition', $html);
            self::assertStringNotContainsString('data-expectation-editor', $html);
            self::assertStringNotContainsString('Ready for human review', $html);
        }
    }

    public function testSelectionIsPendingUntilANativeBindingIsRead(): void
    {
        $sample = $this->sample() + ['candidateSelection' => ['scope' => ['workspace' => 'w0123456789ab', 'artifactPath' => 'src/Focus.php']]];
        $html = $this->render($sample);
        self::assertStringContainsString('data-delivery-candidate value="w0123456789ab"', $html);
        self::assertStringContainsString('This does not approve or activate', $html);
        self::assertStringNotContainsString('data-candidate-bound', $html);
        $sample['declaration'] = ['binding' => ['expectationSeq' => 2], 'scope' => $sample['candidateSelection']['scope']];
        $html = $this->render($sample);
        self::assertStringContainsString('data-candidate-bound', $html);
        self::assertStringNotContainsString('data-delivery-candidate', $html);
    }

    public function testExpectedValuesAndCandidateNamesAreEscaped(): void
    {
        $sample = $this->sample();
        $sample['expectation']['expected']['test']['filter'] = '<script>filter</script>';
        $sample['expectation']['expected']['screen']['name'] = '<img src=x>';
        $sample['expectation']['expected']['screen']['definition'] = ['props' => ['title' => '</pre><script>unsafe</script>']];
        $sample['candidateSelection'] = ['scope' => ['workspace' => '" onfocus="unsafe', 'artifactPath' => '<b>candidate</b>']];
        $html = $this->render($sample);
        foreach (['<script>', '<img', '<b>'] as $tag) {
            self::assertStringNotContainsString($tag, $html);
        }
        self::assertStringContainsString('&lt;script&gt;filter', $html);
        self::assertStringContainsString('&quot; onfocus=&quot;', $html);
        unset($sample['expectation']['expected']['screen']['definition']);
        self::assertStringNotContainsString('data-expected-definition', $this->render($sample));
    }
}
