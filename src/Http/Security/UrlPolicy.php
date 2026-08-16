<?php

/**
 * Grafida — Joomla content editing, untethered.
 *
 * @copyright Copyright (c) 2026 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

declare(strict_types=1);

namespace Grafida\Http\Security;

/**
 * What a given transport is allowed to talk to.
 *
 * Two rules, because Grafida makes two kinds of outbound request and they have
 * genuinely different threat models — not because one of them is held to a lower
 * standard.
 */
enum UrlPolicy: string
{
    /**
     * Requests to a Joomla site, and to everything else on the public Internet
     * (the update check, a template stylesheet, a favicon, a site image).
     *
     * **HTTPS only, with no exception.** The API token in the `Authorization`
     * header is full authority over the site's content; there is no address at
     * which it is acceptable to put that on the wire in the clear.
     */
    case Site = 'site';

    /**
     * Requests to an AI provider.
     *
     * HTTPS, **except** when every address the host resolves to is loopback,
     * private or link-local ({@see IpRanges}). That carve-out is what makes a
     * locally hosted model usable: Ollama, LM Studio and llama.cpp all serve
     * plain HTTP on `127.0.0.1` and offer no way to change it, and a self-signed
     * certificate on a LAN box is not a better answer. A provider anywhere on the
     * public Internet gets no exemption — its API key is as much a credential as
     * the site token is.
     */
    case AiService = 'ai';
}
