<?php
declare(strict_types=1);

namespace Platformsh\ConfigReader;

use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase
{

    /**
     * A mock environment to simulate build time.
     *
     * @var array
     */
    protected $mockEnvironmentBuild = [];

    /**
     * A mock environment to simulate runtime.
     *
     * @var array
     */
    protected $mockEnvironmentDeploy = [];

    public function setUp()
    {
        $env = $this->loadJsonFile('ENV');

        // These sub-values are always encoded.
        foreach (['PLATFORM_APPLICATION', 'PLATFORM_VARIABLES'] as $item) {
            $env[$item] = $this->encode($this->loadJsonFile($item));
        }

        $this->mockEnvironmentBuild = $env;

        // These sub-values are always encoded.
        foreach (['PLATFORM_ROUTES', 'PLATFORM_RELATIONSHIPS'] as $item) {
            $env[$item] = $this->encode($this->loadJsonFile($item));
        }

        $envRuntime = $this->loadJsonFile('ENV_runtime');
        $env = array_merge($env, $envRuntime);

        $this->mockEnvironmentDeploy = $env;

    }

    protected function loadJsonFile(string $name) : array
    {
        return json_decode(file_get_contents("tests/valid/{$name}.json"), true);
    }

    public function test_not_on_platform_returns_correctly() : void
    {
        $config = new Config();

        $this->assertFalse($config->isAvailable());
    }

    public function test_on_platform_returns_correctly_in_runtime() : void
    {
        $config = new Config($this->mockEnvironmentDeploy);

        $this->assertTrue($config->isAvailable());
    }

    public function test_on_platform_returns_correctly_in_build() : void
    {
        $config = new Config($this->mockEnvironmentBuild);

        $this->assertTrue($config->isAvailable());
    }

    public function test_inbuild_in_build_phase_is_true() : void
    {
        $config = new Config($this->mockEnvironmentBuild);

        $this->assertTrue($config->inBuild());
    }

    public function test_inbuild_in_deploy_phase_is_false() : void
    {
        $config = new Config($this->mockEnvironmentDeploy);

        $this->assertFalse($config->inBuild());
    }

    public function _test_buildtime_properties_are_available() : void
    {
        $config = new Config($this->mockEnvironmentBuild);

        $this->assertEquals('/app', $config->appDir);
        $this->assertEquals('app', $config->applicationName);
        $this->assertEquals('test-project', $config->project);
        $this->assertEquals('abc123', $config->treeId);
        $this->assertEquals('def789', $config->entropy);
    }

    public function _test_runtime_properties_are_available() : void
    {
        $config = new Config($this->mockEnvironmentDeploy);

        $this->assertEquals('feature-x', $config->branch);
        $this->assertEquals('feature-x-hgi456', $config->environment);
        $this->assertEquals('/app/web', $config->docRoot);
    }

    public function test_load_routes_in_runtime_works() : void
    {
        $config = new Config($this->mockEnvironmentDeploy);

        $routes = $config->routes();

        $this->assertTrue(is_array($routes));
    }

    public function test_load_routes_in_build_fails() : void
    {
        $this->expectException(\RuntimeException::class);

        $config = new Config($this->mockEnvironmentBuild);
        $routes = $config->routes();
    }

    public function test_get_route_by_id_works() : void
    {
        $config = new Config($this->mockEnvironmentDeploy);

        $route = $config->getRoute('main');

        $this->assertEquals('https://www.{default}/', $route['original_url']);
    }

    public function test_get_non_existent_route_throws_exception() : void
    {
        $this->expectException(\InvalidArgumentException::class);

        $config = new Config($this->mockEnvironmentDeploy);

        $route = $config->getRoute('missing');
    }

    public function test_onenterprise_returns_true_on_enterprise() : void
    {
        $env = $this->mockEnvironmentDeploy;
        $env['PLATFORM_MODE'] = 'enterprise';
        $config = new Config($env);

        $this->assertTrue($config->onEnterprise());
    }

    public function test_onenterprise_returns_false_on_standard() : void
    {
        $env = $this->mockEnvironmentDeploy;
        $config = new Config($env);

        $this->assertFalse($config->onEnterprise());
    }

    public function test_onproduction_on_enterprise_prod_is_true() : void
    {
        $env = $this->mockEnvironmentDeploy;
        $env['PLATFORM_MODE'] = 'enterprise';
        $env['PLATFORM_BRANCH'] = 'production';
        $config = new Config($env);

        $this->assertTrue($config->onProduction());
    }

    public function test_onproduction_on_enterprise_stg_is_false() : void
    {
        $env = $this->mockEnvironmentDeploy;
        $env['PLATFORM_MODE'] = 'enterprise';
        $env['PLATFORM_BRANCH'] = 'staging';
        $config = new Config($env);

        $this->assertFalse($config->onProduction());

    }

    public function test_onproduction_on_standard_prod_is_true() : void
    {
        $env = $this->mockEnvironmentDeploy;
        $env['PLATFORM_BRANCH'] = 'master';
        $config = new Config($env);

        $this->assertTrue($config->onProduction());
    }

    public function test_onproduction_on_standard_stg_is_false() : void
    {
        // The fixture has a non-master branch set by default.
        $env = $this->mockEnvironmentDeploy;
        $config = new Config($env);

        $this->assertFalse($config->onProduction());
    }

    public function test_credentials_existing_relationship_returns() : void
    {
        $env = $this->mockEnvironmentDeploy;
        $config = new Config($env);

        $creds = $config->credentials('database');

        $this->assertEquals('mysql', $creds['scheme']);
        $this->assertEquals('mysql:10.2', $creds['type']);
    }

    public function test_credentials_missing_relationship_throws() : void
    {
        $this->expectException(\InvalidArgumentException::class);

        $env = $this->mockEnvironmentDeploy;
        $config = new Config($env);

        $creds = $config->credentials('does-not-exist');
    }

    public function test_credentials_missing_relationship_index_throws() : void
    {
        $this->expectException(\InvalidArgumentException::class);

        $env = $this->mockEnvironmentDeploy;
        $config = new Config($env);

        $creds = $config->credentials('database', 3);
    }

    public function test_reading_existing_variable_works() : void
    {
        $env = $this->mockEnvironmentDeploy;
        $config = new Config($env);

        $this->assertEquals('someval', $config->variable('somevar'));
    }

    public function test_reading_missing_variable_returns_default() : void
    {
        $env = $this->mockEnvironmentDeploy;
        $config = new Config($env);

        $this->assertEquals('default-val', $config->variable('missing', 'default-val'));
    }

    public function test_variables_returns_on_platform() : void
    {
        $env = $this->mockEnvironmentDeploy;
        $config = new Config($env);

        $vars = $config->variables();

        $this->assertEquals('someval', $vars['somevar']);
    }

    public function testConfig()
    {
        //$this->expectException(\Exception::class);
        //$this->expectExceptionMessage('Error decoding JSON');

        $mockEnv = [
            'PLATFORM_PROJECT' => 'test-project',
            'PLATFORM_ENVIRONMENT' => 'test-environment',
            'PLATFORM_APPLICATION' => $this->encode(['type' => 'php:7.0']),
            'PLATFORM_RELATIONSHIPS' => $this->encode([
                'database' => [0 => ['host' => '127.0.0.1']],
            ]),
            'PLATFORM_NEW' => 'some-new-variable',
        ];

        $config = new Config($mockEnv);

        $this->assertTrue($config->isAvailable());
        $this->assertEquals('php:7.0', $config->application['type']);
        $this->assertEquals('test-project', $config->project);

        $this->assertTrue(isset($config->relationships));
        $this->assertTrue(isset($config->relationships['database'][0]));
        $this->assertEquals('127.0.0.1', $config->relationships['database'][0]['host']);

        /** @noinspection PhpUndefinedFieldInspection */
        $this->assertEquals('some-new-variable', $config->new);
    }

    public function testInvalidJson()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Error decoding JSON, code: 4');

        $config = new Config([
            'PLATFORM_APPLICATION' => 'app',
            'PLATFORM_ENVIRONMENT' => 'test-environment',
            'PLATFORM_VARIABLES' => base64_encode('{some-invalid-json}'),
        ]);

        $config->variables;
    }

    public function testCustomPrefix()
    {
        $config = new Config(['APPLICATION' => 'test-application'], '');
        $this->assertTrue($config->isAvailable());
    }

    /**
     * @param mixed $value
     *
     * @return string
     */
    protected function encode($value)
    {
        return base64_encode(json_encode($value));
    }
}
