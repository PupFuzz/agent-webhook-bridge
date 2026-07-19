<?php

namespace Tests\Unit\Support;

use App\Bridge\Exceptions\ConfigException;
use App\Bridge\Support\ConfigMapping;
use PHPUnit\Framework\TestCase;

class ConfigMappingTest extends TestCase
{
    public function test_absent_key_yields_empty(): void
    {
        $this->assertSame([], ConfigMapping::require([], 'surface', 'surface'));
    }

    public function test_null_value_yields_empty(): void
    {
        $this->assertSame([], ConfigMapping::require(['surface' => null], 'surface', 'surface'));
    }

    public function test_present_mapping_is_returned_unchanged(): void
    {
        $mapping = ['silent_drop_warnings' => false, 'nested' => ['a' => 1]];

        $this->assertSame($mapping, ConfigMapping::require(['surface' => $mapping], 'surface', 'surface'));
    }

    public function test_a_list_is_a_valid_mapping(): void
    {
        // is_array is the gate — a YAML sequence (list) passes; only scalars/null are rejected.
        $this->assertSame(['a', 'b'], ConfigMapping::require(['k' => ['a', 'b']], 'k', 'k'));
    }

    public function test_non_array_throws_with_the_given_label(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('classifier.config.impl_ci_wake must be a mapping');
        ConfigMapping::require(['impl_ci_wake' => 'scalar'], 'impl_ci_wake', 'classifier.config.impl_ci_wake');
    }

    public function test_label_is_independent_of_key_in_the_message(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('surface must be a mapping');
        ConfigMapping::require(['surface' => 'yes'], 'surface', 'surface');
    }
}
