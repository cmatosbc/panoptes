<?php

namespace Panoptes;

enum StreamMode
{
    case READ_PLUS;
    case WRITE_PLUS;
    case APPEND_PLUS;
    case CREATE_PLUS;
    case EXCLUSIVE_PLUS;
    case READ_BINARY_PLUS;
    case WRITE_BINARY_PLUS;
    case APPEND_BINARY_PLUS;
    case CREATE_BINARY_PLUS;
    case EXCLUSIVE_BINARY_PLUS;
    case WRITE_PLUS_BINARY;
    case READ;
    case READ_BINARY;
    case WRITE;
    case WRITE_BINARY;
    case APPEND;
    case APPEND_BINARY;
    case CREATE;
    case CREATE_BINARY;
    case EXCLUSIVE;
    case EXCLUSIVE_BINARY;

    public function isReadable(): bool
    {
        return match($this) {
            self::READ_PLUS, self::WRITE_PLUS, self::APPEND_PLUS,
            self::CREATE_PLUS, self::EXCLUSIVE_PLUS, self::READ_BINARY_PLUS,
            self::WRITE_BINARY_PLUS, self::APPEND_BINARY_PLUS,
            self::CREATE_BINARY_PLUS, self::EXCLUSIVE_BINARY_PLUS,
            self::WRITE_PLUS_BINARY, self::READ, self::READ_BINARY => true,
            default => false
        };
    }

    public function isWritable(): bool
    {
        return match($this) {
            self::READ_PLUS, self::WRITE_PLUS, self::APPEND_PLUS,
            self::CREATE_PLUS, self::EXCLUSIVE_PLUS, self::READ_BINARY_PLUS,
            self::WRITE_BINARY_PLUS, self::APPEND_BINARY_PLUS,
            self::CREATE_BINARY_PLUS, self::EXCLUSIVE_BINARY_PLUS,
            self::WRITE_PLUS_BINARY, self::WRITE, self::WRITE_BINARY,
            self::APPEND, self::APPEND_BINARY, self::CREATE,
            self::CREATE_BINARY, self::EXCLUSIVE, self::EXCLUSIVE_BINARY => true,
            default => false
        };
    }

    public function isReadWrite(): bool
    {
        return match($this) {
            self::READ_PLUS, self::WRITE_PLUS, self::APPEND_PLUS,
            self::CREATE_PLUS, self::EXCLUSIVE_PLUS, self::READ_BINARY_PLUS,
            self::WRITE_BINARY_PLUS, self::APPEND_BINARY_PLUS,
            self::CREATE_BINARY_PLUS, self::EXCLUSIVE_BINARY_PLUS,
            self::WRITE_PLUS_BINARY => true,
            default => false
        };
    }

    public static function fromString(string $mode): ?self
    {
        return match($mode) {
            'r+'  => self::READ_PLUS,
            'w+'  => self::WRITE_PLUS,
            'a+'  => self::APPEND_PLUS,
            'x+'  => self::EXCLUSIVE_PLUS,
            'c+'  => self::CREATE_PLUS,
            'rb+' => self::READ_BINARY_PLUS,
            'r+b' => self::READ_BINARY_PLUS,
            'wb+' => self::WRITE_BINARY_PLUS,
            'w+b' => self::WRITE_PLUS_BINARY,
            'ab+' => self::APPEND_BINARY_PLUS,
            'xb+' => self::EXCLUSIVE_BINARY_PLUS,
            'cb+' => self::CREATE_BINARY_PLUS,
            'r'   => self::READ,
            'rb'  => self::READ_BINARY,
            'w'   => self::WRITE,
            'wb'  => self::WRITE_BINARY,
            'a'   => self::APPEND,
            'ab'  => self::APPEND_BINARY,
            'x'   => self::EXCLUSIVE,
            'xb'  => self::EXCLUSIVE_BINARY,
            'c'   => self::CREATE,
            'cb'  => self::CREATE_BINARY,
            default => null
        };
    }
}
