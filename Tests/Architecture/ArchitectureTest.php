<?php

declare(strict_types=1);

namespace Netresearch\NrPasskeysBe\Tests\Architecture;

use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

/**
 * Architecture rules enforced via PHPStan + phpat.
 *
 * These rules verify the layered architecture of the extension:
 * - Domain models are independent of infrastructure
 * - Services don't depend on controllers
 * - Controllers depend on services, not on each other's internals
 */
final class ArchitectureTest
{
    private const NS = 'Netresearch\\NrPasskeysBe\\';

    public function test_domain_models_do_not_depend_on_controllers(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::NS . 'Domain'))
            ->shouldNotDependOn()
            ->classes(
                Selector::inNamespace(self::NS . 'Controller'),
                Selector::inNamespace(self::NS . 'Middleware'),
                Selector::inNamespace(self::NS . 'Authentication'),
            )
            ->because('Domain models must remain independent of HTTP and auth layers');
    }

    public function test_services_do_not_depend_on_controllers(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::NS . 'Service'))
            ->shouldNotDependOn()
            ->classes(
                Selector::inNamespace(self::NS . 'Controller'),
                Selector::inNamespace(self::NS . 'Middleware'),
            )
            ->because('Services are business logic and must not depend on HTTP handlers');
    }

    public function test_domain_models_do_not_depend_on_services(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::NS . 'Domain'))
            ->shouldNotDependOn()
            ->classes(Selector::inNamespace(self::NS . 'Service'))
            ->because('Domain models must not depend on service layer');
    }

    public function test_configuration_dto_does_not_depend_on_services(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::NS . 'Configuration'))
            ->shouldNotDependOn()
            ->classes(
                Selector::inNamespace(self::NS . 'Service'),
                Selector::inNamespace(self::NS . 'Controller'),
                Selector::inNamespace(self::NS . 'Authentication'),
            )
            ->because('Configuration DTOs are pure value objects');
    }

    public function test_credential_model_is_final(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::classname(self::NS . 'Domain\\Model\\Credential'))
            ->shouldBeFinal()
            ->because('Domain models should not be extended');
    }

    public function test_configuration_dto_is_final(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::classname(self::NS . 'Configuration\\ExtensionConfiguration'))
            ->shouldBeFinal()
            ->because('Configuration DTOs should not be extended');
    }

    public function test_middleware_does_not_depend_on_services(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::NS . 'Middleware'))
            ->shouldNotDependOn()
            ->classes(
                Selector::inNamespace(self::NS . 'Service'),
                Selector::inNamespace(self::NS . 'Domain'),
                Selector::inNamespace(self::NS . 'Authentication'),
            )
            ->because('PublicRouteResolver only dispatches routes, no business logic');
    }

    public function test_event_listeners_do_not_depend_on_controllers(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::NS . 'EventListener'))
            ->shouldNotDependOn()
            ->classes(Selector::inNamespace(self::NS . 'Controller'))
            ->because('Event listeners must not depend on controllers');
    }
}
