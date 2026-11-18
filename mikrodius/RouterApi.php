<?php
namespace Mikrodius;

/**
 * The fixed RouterOS menu operations the provisioning steps use. There is deliberately
 * no free-form command method: every caller names a menu and typed properties.
 */
interface RouterApi
{
    /** Rows of a menu, e.g. `/ip/hotspot/profile`, each with its `.id`. */
    public function rows(string $menu): array;

    /** Adds an entry and returns its `.id`. */
    public function add(string $menu, array $props): string;

    public function set(string $menu, string $id, array $props): void;

    /** Sets a menu without entries, e.g. `/ppp/aaa`. */
    public function setGlobal(string $menu, array $props): void;

    public function remove(string $menu, string $id): void;

    /** Replies of `/ping` to a tunnel address. */
    public function ping(string $address, int $count): array;

    /** Creates a small text file through `/file/print file=…` and sets its contents. */
    public function writeFile(string $name, string $contents): void;

    public function importCertificate(string $file): void;
}
