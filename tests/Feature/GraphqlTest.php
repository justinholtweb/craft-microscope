<?php

declare(strict_types=1);

use CraftCms\Cms\Gql\Data\GqlSchema;
use CraftCms\Cms\Gql\Gql;
use JustinHoltWeb\Microscope\Data\Finding;
use JustinHoltWeb\Microscope\Tests\Support\FakeCheck;

function microscopeGql(array $scope, string $query, bool $public = false): array
{
    $gql = app(Gql::class);
    $gql->invalidateCaches();

    return $gql->executeQuery(new GqlSchema(['name' => 'Test', 'scope' => $scope, 'isPublic' => $public]), $query);
}

beforeEach(function () {
    $this->scan = $this->scanWith([new FakeCheck('opcache', 'php', [[Finding::SEVERITY_CRITICAL, 'OPcache is off']])]);
});

it('answers a schema with the scope', function () {
    $result = microscopeGql(['microscopeScans:read'], '{ microscopeScan { id grade categoryScores { category score } findings { title severity fingerprint } } microscopeChecks { handle enabled } }');

    expect($result['errors'] ?? null)->toBeNull()
        ->and($result['data']['microscopeScan'])->toMatchArray(['id' => $this->scan->id, 'grade' => 'B'])
        ->and($result['data']['microscopeScan']['categoryScores'])->toBe([['category' => 'php', 'score' => 75]])
        ->and($result['data']['microscopeScan']['findings'])->toBe([['title' => 'OPcache is off', 'severity' => 'critical', 'fingerprint' => 'opcache:']])
        ->and($result['data']['microscopeChecks'])->toBe([['handle' => 'opcache', 'enabled' => true]]);
});

it('gives nothing to a schema without the scope, or to the public schema', function () {
    $without = microscopeGql([], '{ microscopeScans { id } }');
    expect($without['data']['microscopeScans'] ?? null)->toBeNull();

    $public = microscopeGql(['microscopeScans:read'], '{ microscopeScans { id } }', public: true);
    expect($public['data']['microscopeScans'] ?? null)->toBeEmpty();
});
