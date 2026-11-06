<?php

declare(strict_types=1);

namespace MattLeads\AgentPublishingBundle\Lint\Rule;

use MattLeads\AgentPublishingBundle\Lint\LintCheck;
use MattLeads\AgentPublishingBundle\Lint\LintRuleInterface;
use MattLeads\AgentPublishingBundle\Lint\LintSubject;

final class ImagesAltRule implements LintRuleInterface
{
    public function name(): string
    {
        return 'images_alt';
    }

    public function check(LintSubject $subject): LintCheck
    {
        $images = $subject->content()->images;
        if ([] === $images) {
            return LintCheck::pass($this->name(), 'No images.');
        }
        $missing = array_values(array_filter($images, static fn(array $i) => '' === $i['alt']));
        if ([] !== $missing) {
            return LintCheck::fail($this->name(), \sprintf('%d of %d image(s) have no alt text (first: %s).', \count($missing), \count($images), $missing[0]['src']), 'Describe what the image shows. Alt text is read by screen readers and indexed by image search.');
        }

        return LintCheck::pass($this->name(), \sprintf('%d image(s), all with alt text.', \count($images)));
    }
}
