<?php

namespace tests\App\Entities;

use App\Entities\Admin;
use PHPUnit\Framework\TestCase;

class AdminTest extends TestCase
{
    public function testConstructorWithValidData()
    {
        $admin = new Admin('john_doe', 'john@example.com', 'John Doe');

        $this->assertEquals('john_doe', $admin->getUsername());
        $this->assertEquals('john@example.com', $admin->getEmail());
        $this->assertEquals('John Doe', $admin->getName());
        $this->assertEquals('admin', $admin->getRole());
        $this->assertTrue($admin->isActive());
    }

    public function testConstructorWithEmptyUsername()
    {
        $admin = new Admin('', 'test@example.com', 'Test User');
        $this->assertInstanceOf(Admin::class, $admin);

        $result = $admin->validate();
        $this->assertFalse((bool)$result['valid']);
        $this->assertNotEmpty($result['errors']);
    }

    public function testUsernameGetterSetter()
    {
        $admin = new Admin('initial', 'test@example.com', 'Test');
        $result = $admin->setUsername('new_username');

        $this->assertSame($admin, $result);
        $this->assertEquals('new_username', $admin->getUsername());
    }

    public function testUsernameWithVeryLongString()
    {
        $admin = new Admin('test', 'test@example.com', 'Test');
        $longUsername = str_repeat('a', 255);

        $admin->setUsername($longUsername);
        $this->assertEquals($longUsername, $admin->getUsername());
    }

    public function testSetPasswordWithHashAndVerify()
    {
        $admin = new Admin('test', 'test@example.com', 'Test');
        $admin->setPasswordWithHash('SecurePass123!');

        $this->assertTrue($admin->verifyPassword('SecurePass123!'));
        $this->assertFalse($admin->verifyPassword('wrongpassword'));
    }

    public function testPasswordNeedsRehash()
    {
        $admin = new Admin('test', 'test@example.com', 'Test');
        $admin->setPasswordWithHash('password123', ['cost' => 10]);

        $this->assertTrue($admin->passwordNeedsRehash());
    }

    public function testCanBeArchivedByAnotherAdmin()
    {
        $admin = new Admin('target', 'target@example.com', 'Target Admin');
        $result = $admin->canBeArchivedBy(2, 2);

        $this->assertArrayHasKey('can', $result);
        $this->assertArrayHasKey('reason', $result);
        $this->assertTrue((bool)$result['can']);
        $this->assertEmpty($result['reason']);
    }

    public function testCannotArchiveLastSuperAdmin()
    {
        $admin = new Admin('last_super', 'super@example.com', 'Last Super');
        $admin->promoteToSuperAdmin();
        $result = $admin->canBeArchivedBy(1, 1);

        $this->assertArrayHasKey('can', $result);
        $this->assertArrayHasKey('reason', $result);
        $this->assertFalse((bool)$result['can']);
        $this->assertNotEmpty($result['reason']);
    }

    public function testCompleteAdminLifecycle()
    {
        $admin = new Admin('lifecycle', 'life@example.com', 'Lifecycle Test');

        $this->assertTrue($admin->isRegularAdmin());
        $admin->promoteToSuperAdmin();
        $this->assertTrue($admin->isSuperAdmin());
        $admin->demoteToAdmin();
        $this->assertTrue($admin->isRegularAdmin());

        $admin->deactivate();
        $this->assertFalse($admin->isActive());
        $admin->activate();
        $this->assertTrue($admin->isActive());

        $admin->recordFailedLogin();
        $admin->recordFailedLogin();
        $this->assertEquals(2, $admin->getLoginAttempts());
        $this->assertFalse($admin->isLocked(5));

        $admin->recordFailedLogin();
        $admin->recordFailedLogin();
        $admin->recordFailedLogin();
        $this->assertTrue($admin->isLocked(5));

        $admin->resetLoginAttempts();
        $this->assertEquals(0, $admin->getLoginAttempts());
    }

    /**
     * @dataProvider usernameProvider
     */
    public function testUsernameValidation($username, $expectedValid)
    {
        $admin = new Admin('initial', 'test@example.com', 'Test');
        $admin->setUsername($username);

        $result = $admin->validate();

        $this->assertArrayHasKey('valid', $result);
        $this->assertArrayHasKey('errors', $result);

        $actualValid = (bool)$result['valid'];

        if ($expectedValid) {
            $this->assertTrue($actualValid);
        } else {
            $this->assertFalse($actualValid);
            $this->assertNotEmpty($result['errors']);
        }
    }

    public function usernameProvider()
    {
        return [
            ['validuser', true],
            ['user123', true],
            ['user-name', false],
            ['', false],
            [str_repeat('a', 256), true],
            ['user@name', false],
            ['user_name', true],
        ];
    }

    public function testRecordLoginSetsCurrentTime()
    {
        $admin = new Admin('test', 'test@example.com', 'Test');
        $admin->recordLogin();

        $this->assertNotNull($admin->getLastLogin());
    }
}
