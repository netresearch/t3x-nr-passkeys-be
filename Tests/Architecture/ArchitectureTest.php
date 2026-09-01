<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Tests\Architecture;

use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

/**
 * Architecture rules enforced via PHPStan + phpat.
 *
 * Layering (inner → outer):
 *   Domain (Model + Dto) → Service → Controller / Auth / Middleware / EventListener / UI
 *
 * Invariants:
 *   - Domain layer does not depend on extension infrastructure namespaces
 *   - Services never depend on HTTP/controller layer
 *   - Controllers do not depend on each other
 *   - All non-abstract, non-extending classes are final
 *   - DTOs are pure data containers (framework deps only for third-party value types)
 */
final class ArchitectureTest
{
    private const NS = 'Netresearch\NrPasskeysBe\\';

    // ─── Layer isolation ─────────────────────────────────────────────
    public function test_domain_does_not_depend_on_infrastructure(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::NS . 'Domain'))
            ->shouldNot()
            ->dependOn()
            ->classes(
                Selector::inNamespace(self::NS . 'Controller'),
                Selector::inNamespace(self::NS . 'Middleware'),
                Selector::inNamespace(self::NS . 'Authentication'),
                Selector::inNamespace(self::NS . 'EventListener'),
                Selector::inNamespace(self::NS . 'UserSettings'),
                Selector::inNamespace(self::NS . 'Form'),
                Selector::inNamespace(self::NS . 'Service'),
            )
            ->because('Domain layer (Model + Dto) must have zero outward dependencies');
    }

    public function test_configuration_is_pure_value_object(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::NS . 'Configuration'))
            ->shouldNot()
            ->dependOn()
            ->classes(
                Selector::inNamespace(self::NS . 'Service'),
                Selector::inNamespace(self::NS . 'Controller'),
                Selector::inNamespace(self::NS . 'Authentication'),
                Selector::inNamespace(self::NS . 'Middleware'),
                Selector::inNamespace(self::NS . 'EventListener'),
                Selector::inNamespace(self::NS . 'UserSettings'),
                Selector::inNamespace(self::NS . 'Form'),
            )
            ->because('Configuration DTOs are pure value objects');
    }

    public function test_services_do_not_depend_on_http_layer(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::NS . 'Service'))
            ->shouldNot()
            ->dependOn()
            ->classes(
                Selector::inNamespace(self::NS . 'Controller'),
                Selector::inNamespace(self::NS . 'Middleware'),
                Selector::inNamespace(self::NS . 'EventListener'),
                Selector::inNamespace(self::NS . 'UserSettings'),
                Selector::inNamespace(self::NS . 'Form'),
            )
            ->because('Services must not depend on HTTP handlers or UI components');
    }

    public function test_route_resolver_does_not_depend_on_business_logic(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::classname(self::NS . 'Middleware\PublicRouteResolver'))
            ->shouldNot()
            ->dependOn()
            ->classes(
                Selector::inNamespace(self::NS . 'Service'),
                Selector::inNamespace(self::NS . 'Domain'),
                Selector::inNamespace(self::NS . 'Authentication'),
                Selector::inNamespace(self::NS . 'Controller'),
            )
            ->because('PublicRouteResolver only dispatches routes, no business logic');
    }

    public function test_event_listeners_do_not_depend_on_controllers(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::NS . 'EventListener'))
            ->shouldNot()
            ->dependOn()
            ->classes(
                Selector::inNamespace(self::NS . 'Controller'),
                Selector::inNamespace(self::NS . 'Middleware'),
                Selector::inNamespace(self::NS . 'Authentication'),
                Selector::inNamespace(self::NS . 'UserSettings'),
                Selector::inNamespace(self::NS . 'Form'),
            )
            ->because('Event listeners inject UI data, not controller behavior');
    }

    public function test_authentication_does_not_depend_on_controllers(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::NS . 'Authentication'))
            ->shouldNot()
            ->dependOn()
            ->classes(
                Selector::inNamespace(self::NS . 'Controller'),
                Selector::inNamespace(self::NS . 'Middleware'),
                Selector::inNamespace(self::NS . 'EventListener'),
                Selector::inNamespace(self::NS . 'UserSettings'),
                Selector::inNamespace(self::NS . 'Form'),
            )
            ->because('Authentication service is infrastructure, not an HTTP consumer');
    }

    public function test_user_settings_does_not_depend_on_controllers(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::NS . 'UserSettings'))
            ->shouldNot()
            ->dependOn()
            ->classes(
                Selector::inNamespace(self::NS . 'Controller'),
                Selector::inNamespace(self::NS . 'Middleware'),
                Selector::inNamespace(self::NS . 'Authentication'),
                Selector::inNamespace(self::NS . 'EventListener'),
            )
            ->because('UserSettings is a UI component, not a controller consumer');
    }

    public function test_form_elements_do_not_depend_on_controllers(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::NS . 'Form'))
            ->shouldNot()
            ->dependOn()
            ->classes(
                Selector::inNamespace(self::NS . 'Controller'),
                Selector::inNamespace(self::NS . 'Middleware'),
                Selector::inNamespace(self::NS . 'Authentication'),
                Selector::inNamespace(self::NS . 'EventListener'),
                Selector::inNamespace(self::NS . 'UserSettings'),
            )
            ->because('Form elements render UI, they must not depend on controllers');
    }

    // ─── Finality enforcement ────────────────────────────────────────
    public function test_all_services_are_final(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::NS . 'Service'))
            ->should()
            ->beFinal()
            ->because(
                'Services are leaf classes — composition over inheritance',
            );
    }

    public function test_all_controllers_are_final(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::NS . 'Controller'))
            ->excluding(Selector::isInterface())
            ->should()
            ->beFinal()
            ->because(
                'Controllers are leaf classes — composition over inheritance',
            );
    }

    public function test_all_dtos_are_final(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::NS . 'Domain\Dto'))
            ->should()
            ->beFinal()
            ->because(
                'DTOs are immutable value objects that must not be extended',
            );
    }

    public function test_domain_models_are_final(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::NS . 'Domain\Model'))
            ->should()
            ->beFinal()
            ->because(
                'Domain models are entities that must not be extended',
            );
    }

    public function test_configuration_is_final(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::NS . 'Configuration'))
            ->should()
            ->beFinal()
            ->because(
                'Configuration value objects must not be extended',
            );
    }

    public function test_middleware_is_final(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::NS . 'Middleware'))
            ->should()
            ->beFinal()
            ->because(
                'Middleware is a leaf class — composition over inheritance',
            );
    }

    public function test_event_listeners_are_final(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::NS . 'EventListener'))
            ->should()
            ->beFinal()
            ->because(
                'Event listeners are leaf classes — composition over inheritance',
            );
    }

    public function test_user_settings_is_final(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::NS . 'UserSettings'))
            ->should()
            ->beFinal()
            ->because(
                'UserSettings panel is a leaf class — composition over inheritance',
            );
    }

    public function test_authentication_is_final(): Rule
    {
        // PasskeyAuthenticationService is the one documented exception — it extends the
        // TYPO3 AbstractAuthenticationService base class and is wired into the auth
        // chain. Any *other* class added here must still be final.
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::NS . 'Authentication'))
            ->excluding(
                Selector::classname(self::NS . 'Authentication\PasskeyAuthenticationService'),
            )
            ->should()
            ->beFinal()
            ->because('Authentication classes are leaf classes — composition over inheritance');
    }

    public function test_form_is_final(): Rule
    {
        // PasskeyInfoElement is the one documented exception — it extends the FormEngine
        // AbstractFormElement base class. Any *other* class added here must still be final.
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::NS . 'Form'))
            ->excluding(
                Selector::classname(self::NS . 'Form\Element\PasskeyInfoElement'),
            )
            ->should()
            ->beFinal()
            ->because('Form classes are leaf classes — composition over inheritance');
    }
}
