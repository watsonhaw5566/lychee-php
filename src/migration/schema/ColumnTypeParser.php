<?php

declare(strict_types=1);

namespace Lychee\migration\schema;

/**
 * 将数据库原生的列类型声明解析为 {@see \Lychee\migration\Table} 构建器可识别的逻辑类型。
 *
 * 这是 {@see \Lychee\migration\Table::mapType()} 的逆向过程：
 * 输入如 "varchar(255)"、"bigint(20) unsigned"、"decimal(10,2)"、"enum('a','b')"，
 * 输出归一化后的 type / length / precision / scale / unsigned / values。
 */
final class ColumnTypeParser
{
    /**
     * @return array{type: string, length: ?int, precision: ?int, scale: ?int, unsigned: bool, values: list<string>}
     */
    public static function parse(string $declaration): array
    {
        $declaration = strtolower(trim($declaration));

        $unsigned = false;
        if (str_ends_with($declaration, ' unsigned')) {
            $unsigned    = true;
            $declaration = rtrim(substr($declaration, 0, -strlen(' unsigned')));
        }

        // 去掉 zerofill 等修饰
        if (str_ends_with($declaration, ' zerofill')) {
            $declaration = rtrim(substr($declaration, 0, -strlen(' zerofill')));
        }

        if (!preg_match('/^([a-z]+)(?:\((.*)\))?$/', $declaration, $matches)) {
            return ['type' => $declaration, 'length' => null, 'precision' => null, 'scale' => null, 'unsigned' => $unsigned, 'values' => []];
        }

        $base   = $matches[1];
        $params = $matches[2] ?? '';

        $result = [
            'type'      => $base,
            'length'    => null,
            'precision' => null,
            'scale'     => null,
            'unsigned'  => $unsigned,
            'values'    => [],
        ];

        switch ($base) {
            case 'bool':
            case 'boolean':
                $result['type'] = 'boolean';

                return $result;

            case 'tinyint':
                $length = $params !== '' ? (int) $params : null;
                if ($length === 1) {
                    // tinyint(1) 约定为布尔
                    $result['type'] = 'boolean';
                } else {
                    $result['type']   = 'tinyinteger';
                    $result['length'] = $length;
                }

                return $result;

            case 'smallint':
                $result['type']   = 'smallinteger';
                $result['length'] = $params !== '' ? (int) $params : null;

                return $result;

            case 'mediumint':
                $result['type']   = 'integer';
                $result['length'] = $params !== '' ? (int) $params : null;

                return $result;

            case 'int':
            case 'integer':
                $result['type']   = 'integer';
                $result['length'] = $params !== '' ? (int) $params : null;

                return $result;

            case 'bigint':
                $result['type']   = 'biginteger';
                $result['length'] = $params !== '' ? (int) $params : null;

                return $result;

            case 'float':
                $result['type']   = 'float';
                $result['length'] = $params !== '' ? (int) explode(',', $params)[0] : null;

                return $result;

            case 'double':
                $result['type']   = 'double';
                $result['length'] = $params !== '' ? (int) explode(',', $params)[0] : null;

                return $result;

            case 'decimal':
            case 'dec':
            case 'numeric':
                $result['type'] = 'decimal';
                if ($params !== '') {
                    $parts               = array_map('intval', explode(',', $params));
                    $result['precision'] = $parts[0] ?? null;
                    $result['scale']     = $parts[1] ?? null;
                }

                return $result;

            case 'char':
                $result['type']   = 'char';
                $result['length'] = $params !== '' ? (int) $params : 255;

                return $result;

            case 'varchar':
                $result['type']   = 'string';
                $result['length'] = $params !== '' ? (int) $params : 255;

                return $result;

            case 'blob':
            case 'binary':
            case 'varbinary':
            case 'tinyblob':
            case 'mediumblob':
            case 'longblob':
                $result['type'] = 'binary';

                return $result;

            case 'enum':
            case 'set':
                $result['type']   = $base;
                $result['values'] = self::parseEnumValues($params);

                return $result;

                // text / tinytext / mediumtext / longtext / json / date / datetime /
                // timestamp / time / year 等保持原名，由构建器 mapType 透传或映射
            default:
                if ($params !== '' && preg_match('/^\d+$/', $params)) {
                    // datetime(6) / timestamp(3) / time(2) 等带精度的类型
                    $result['length'] = (int) $params;
                }

                return $result;
        }
    }

    /**
     * 解析 enum / set 的取值列表，如 "a,'b''c',d"。
     *
     * @return list<string>
     */
    private static function parseEnumValues(string $params): array
    {
        if (preg_match_all("/'((?:[^']|'')*)'/", $params, $matches) > 0) {
            return array_map(static fn (string $v): string => str_replace("''", "'", $v), $matches[1]);
        }

        return [];
    }
}
