<?php

declare(strict_types=1);

namespace App\Services\Channel;

use InvalidArgumentException;

/**
 * One ordered step of the `ON_PREMISE → CLOUD_API` migration, with **what breaks if it is
 * taken out of order** — the machine-readable half of the *"documented migration path"*
 * Req 8.9 / A8 asks for (design § Channel Mode 2.2 mode 3).
 *
 * ```php
 * foreach (OnPremiseDeprecation::migrationPath() as $step) {
 *     // the panel renders order, title, detail, and the ordering warning
 *     $step->order; $step->title; $step->detail; $step->breaksIfEarly;
 * }
 * ```
 *
 * ## Why the path is data and not prose in a docblock
 *
 * Req 8.9 requires the mode be *"marked deprecated in UI"* **and** the path be *"exposed"*.
 * A paragraph in a class comment satisfies neither: nobody outside this repository reads it,
 * and a panel cannot render it. A list of these, on the other hand, is rendered by the mode
 * picker, quoted in `ChannelHealth::$detail`, and asserted by a test — so the day a step is
 * wrong, something says so.
 *
 * ## `breaksIfEarly` is the field that earns this class
 *
 * A migration checklist whose steps are interchangeable does not need ordering, and this one
 * is not interchangeable: the destructive step (deleting the number from the On-Premise
 * client) is **irreversible in the middle**, and doing it before the Cloud API registration
 * exists leaves the tenant with a number that is on neither backend. So every step states the
 * consequence of running it too early, in the tenant's own terms, rather than the checklist
 * relying on the reader to infer it from the order.
 */
final readonly class OnPremiseMigrationStep
{
    /**
     * @param  int  $order  1-based position; the steps are **not** interchangeable
     * @param  string  $title  the imperative a tenant sees in the panel
     * @param  string  $detail  what to do, in the tenant's own terms
     * @param  string  $breaksIfEarly  what goes wrong if this step runs before the ones above it
     * @param  bool  $irreversible  true when the step cannot be undone without re-onboarding
     *
     * @throws InvalidArgumentException when the step could not be rendered or ordered
     */
    public function __construct(
        public int $order,
        public string $title,
        public string $detail,
        public string $breaksIfEarly,
        public bool $irreversible = false,
    ) {
        if ($this->order < 1) {
            throw new InvalidArgumentException(
                'A migration step needs a 1-based order: the whole point of the path is that its steps '
                .'are not interchangeable.'
            );
        }

        foreach (['title' => $this->title, 'detail' => $this->detail, 'breaksIfEarly' => $this->breaksIfEarly] as $field => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException(sprintf(
                    'A migration step needs a non-empty [%s]: a blank one renders as a checklist row a '
                    .'tenant cannot act on.',
                    $field,
                ));
            }
        }
    }

    /**
     * The step as a panel-renderable row.
     *
     * Every field is platform-authored prose — no tenant input, no identifier, no credential —
     * so unlike `ChannelCredentials` this type is safe to serialise, and being serialisable is
     * the point: the mode picker is a Livewire component and this has to reach it.
     *
     * @return array{order: int, title: string, detail: string, breaksIfEarly: string, irreversible: bool}
     */
    public function toArray(): array
    {
        return [
            'order' => $this->order,
            'title' => $this->title,
            'detail' => $this->detail,
            'breaksIfEarly' => $this->breaksIfEarly,
            'irreversible' => $this->irreversible,
        ];
    }
}
