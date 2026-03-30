#!/usr/bin/env node
/**
 * Deploy TPIX Token Factory V2 + NFT Factory
 * Runs on production server via GitHub Actions
 *
 * Usage: DEPLOYER_KEY=0x... node deploy-factory-v2-remote.mjs
 *
 * Developed by Xman Studio
 */

import { ethers } from "ethers";
import { readFileSync } from "fs";

async function main() {
  const rpc = "http://127.0.0.1:8545";
  const privateKey = process.env.DEPLOYER_KEY;
  if (!privateKey) {
    console.error("ERROR: DEPLOYER_KEY not set");
    process.exit(1);
  }

  const provider = new ethers.JsonRpcProvider(rpc, { chainId: 4289, name: "TPIX Chain" });
  const wallet = new ethers.Wallet(privateKey, provider);
  console.log("Deployer:", wallet.address);
  console.log("Balance:", ethers.formatEther(await provider.getBalance(wallet.address)), "TPIX");

  // Check block gas limit
  const block = await provider.getBlock("latest");
  console.log("Block gas limit:", block.gasLimit.toString());

  // Load artifacts
  const v2Art = JSON.parse(readFileSync("artifacts/contracts/factory/TPIXTokenFactoryV2.sol/TPIXTokenFactoryV2.json", "utf8"));
  const nftArt = JSON.parse(readFileSync("artifacts/contracts/factory/TPIXNFTFactory.sol/TPIXNFTFactory.json", "utf8"));

  console.log("V2 bytecode size:", Math.round(v2Art.bytecode.length / 2), "bytes");
  console.log("NFT bytecode size:", Math.round(nftArt.bytecode.length / 2), "bytes");

  // Deploy TPIXTokenFactoryV2
  console.log("\n[1/2] Deploying TPIXTokenFactoryV2...");
  try {
    const v2Factory = new ethers.ContractFactory(v2Art.abi, v2Art.bytecode, wallet);
    const gasEstimate = await provider.estimateGas({
      data: v2Art.bytecode,
      from: wallet.address,
      gasPrice: 0,
    });
    console.log("  Estimated gas:", gasEstimate.toString());

    const v2 = await v2Factory.deploy({ gasPrice: 0, gasLimit: gasEstimate * 2n });
    const v2Receipt = await v2.deploymentTransaction().wait();
    const v2Addr = await v2.getAddress();
    console.log("  ✅ TPIXTokenFactoryV2:", v2Addr);
    console.log("  Gas used:", v2Receipt.gasUsed.toString());

    // Deploy TPIXNFTFactory
    console.log("\n[2/2] Deploying TPIXNFTFactory...");
    const nftFactoryDeploy = new ethers.ContractFactory(nftArt.abi, nftArt.bytecode, wallet);
    const nftGasEstimate = await provider.estimateGas({
      data: nftArt.bytecode,
      from: wallet.address,
      gasPrice: 0,
    });
    console.log("  Estimated gas:", nftGasEstimate.toString());

    const nft = await nftFactoryDeploy.deploy({ gasPrice: 0, gasLimit: nftGasEstimate * 2n });
    const nftReceipt = await nft.deploymentTransaction().wait();
    const nftAddr = await nft.getAddress();
    console.log("  ✅ TPIXNFTFactory:", nftAddr);
    console.log("  Gas used:", nftReceipt.gasUsed.toString());

    // Verify contracts respond
    const v2Contract = new ethers.Contract(v2Addr, v2Art.abi, provider);
    const nftContract = new ethers.Contract(nftAddr, nftArt.abi, provider);
    console.log("\nVerification:");
    console.log("  V2 owner:", await v2Contract.owner());
    console.log("  V2 totalTokens:", (await v2Contract.totalTokens()).toString());
    console.log("  NFT owner:", await nftContract.owner());
    console.log("  NFT totalNFTs:", (await nftContract.totalNFTs()).toString());

    // Output for parsing
    console.log("\n═══════════════════════════════════════════");
    console.log("TOKEN_FACTORY_V2_ADDRESS=" + v2Addr);
    console.log("NFT_FACTORY_ADDRESS=" + nftAddr);
    console.log("═══════════════════════════════════════════");
  } catch (err) {
    console.error("Deploy failed:", err.message);
    if (err.info) console.error("Info:", JSON.stringify(err.info));
    if (err.error) console.error("Error detail:", err.error.message || err.error);
    process.exit(1);
  }
}

main().catch((e) => {
  console.error("Fatal:", e.message || e);
  process.exit(1);
});
