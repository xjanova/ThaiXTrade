<?php

/*
 * Where the chain's own infrastructure physically runs (IBFT validators + public bootnodes).
 *
 * The /validators map gets coordinates for project-run nodes from this file only.
 * Every entry must be a real, checked location — the old page placed validators in Thai
 * cities by index, which was fiction and got removed (bd0a1076). Coordinates are
 * city-level (the datacenter's city, rounded), never a street address.
 *
 * Mirror of TPIX-Masternode electron/infra-sites.js — move a node = edit both.
 *
 * 123.253.62.252 — checked 2026-11-06 from the host itself (ipinfo.io / ip-api.com):
 *   Colodee Digital Network, AS136523, Bangkok TH. All 4 validators and the public
 *   bootnode (container tpix-bootnode, TCP 30301) run on this one host.
 *
 * runs_validators = the chain's IBFT validator set runs on this site.
 * A bootnode (masternode.public_bootnodes) whose IP is not listed gets no pin.
 */
return [
    'sites' => [
        [
            'id' => 'chain-host-bkk',
            'ip' => '123.253.62.252',
            'city' => 'Bangkok',
            'country_code' => 'TH',
            'country_name' => 'Thailand',
            'latitude' => 13.75,
            'longitude' => 100.5,
            'provider' => 'Colodee Digital Network (AS136523)',
            'runs_validators' => true,
        ],
    ],
];
