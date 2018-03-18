<?php

use Mockery;

use Illuminate\Support\ServiceProvider;

use Sule\BankStatements\Provider\LaravelServiceProvider;
use Sule\BankStatements\Account;
use Sule\BankStatements\Statement;

class ServiceProviderTest extends TestCase
{
    /**
     * @var array
     */
    protected $config;

    /**
     * @var ServiceProvider
     */
    protected $provider;

    /**
     * Define environment setup.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return void
     */
    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('sule.bank-statements', $this->getConfig());
    }

    public function setUp()
    {
        parent::setUp();

        $this->config = $this->getConfig();

        $app = Mockery::mock(Application::class);
        $this->provider = new LaravelServiceProvider($app);
    }

    /**
     * @test
     */
    public function itCanBeConstructed()
    {
        $this->assertInstanceOf(ServiceProvider::class, $this->provider);
    }

    /**
     * @test
     */
    public function testProviders()
    {
        $this->assertContains(Account::class, $this->provider->provides());
        $this->assertContains(Statement::class, $this->provider->provides());
    }

    /**
     * Return config.
     *
     * @return array
     */
    protected function getConfig()
    {
        return (include './config/config.php');
    }
}
