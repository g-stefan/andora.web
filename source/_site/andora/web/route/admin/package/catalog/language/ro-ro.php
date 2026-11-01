<?php
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: Apache-2.0

defined("ANDORA") or die("Forbidden");

// The strings of admin/package are loaded first (Page::init)
return array(
    "loading" => "Se incarca registrele...",
    "registries" => "Registre",
    "registry" => "Registru",
    "official" => "oficial",
    "packages" => "pachete",
    "packages.title" => "Pachete",
    "no-packages" => "Niciun pachet.",
    "not-configured" => "Neconfigurat: adauga registrul unui editor de pachete.",
    "remove" => "Sterge",
    "registry-add" => "Adauga un registru",
    "registry-add.submit" => "Adauga",
    "registry-add-hint" => "Nume: litere mici, cifre si -. URL: adresa https a indexului (JSON).",
    "registry-name.legend" => "Nume",
    "registry-url.legend" => "URL-ul indexului",
    "registry-remove" => "Sterge registrul",
    "registry-remove.submit" => "Sterge",
    "registry-add-ok" => "Registrul a fost adaugat.",
    "registry-remove-ok" => "Registrul a fost sters.",
    "downloading" => "Se descarca...",
    "catalog-action.install" => "Instaleaza",
    "catalog-action.upgrade" => "Actualizeaza",
    "catalog-action.installed" => "Instalat",
    "catalog-action.older" => "Este instalata o versiune mai noua",
    "registry-not-found" => "Registrul nu a fost gasit",
    "registry-exists" => "Exista un registru cu acest nume",
    "invalid-registry-name" => "Nume invalid (litere mici, cifre si -, nu official)",
    "invalid-url" => "URL invalid (doar https, fara utilizator sau parola)",
    "host-not-found" => "Serverul nu a fost gasit",
    "address-not-allowed" => "Refuzat: serverul are o adresa locala sau privata",
    "too-many-redirects" => "Prea multe redirectionari",
    "http-error" => "Serverul a raspuns cu o eroare",
    "timeout" => "Serverul nu a raspuns la timp",
    "download-error" => "Descarcarea a esuat",
    "invalid-index" => "Indexul registrului nu este valid",
    "catalog-expired" => "Catalogul este prea vechi, deschide pagina din nou",
    "package-not-in-catalog" => "Pachetul nu este in catalog",
    "checksum-mismatch" => "Refuzat: sha256 al arhivei descarcate nu corespunde indexului",
    "too-large" => "Fisierul este prea mare",
    "catalog-mismatch" => "Refuzat: arhiva este alt pachet sau alta versiune decat in index"
);
