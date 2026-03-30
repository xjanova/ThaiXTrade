#!/usr/bin/env node
/**
 * Deploy TPIX Token Factory V2 + NFT Factory
 * Runs on production server via GitHub Actions
 *
 * Env vars:
 *   DEPLOYER_KEY   - Private key for deploying
 *   V2_ARTIFACT    - Path to TPIXTokenFactoryV2.json (optional)
 *   NFT_ARTIFACT   - Path to TPIXNFTFactory.json (optional)
 *
 * Developed by Xman Studio
 */

import { readFileSync, writeFileSync } from "fs";

// Catch unhandled rejections
process.on("unhandledRejection", (reason) => {
  console.log("UNHANDLED REJECTION:", reason);
  if (reason instanceof Error) {
    console.log("Stack:", reason.stack);
  }
  process.exit(1);
});

process.on("uncaughtException", (err) => {
  console.log("UNCAUGHT EXCEPTION:", err.message);
  console.log("Stack:", err.stack);
  process.exit(1);
});

// Dynamic import ethers (works with NODE_PATH or local node_modules)
const { ethers } = await import("ethers");
console.log("ethers version:", ethers.version);

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

  const block = await provider.getBlock("latest");
  console.log("Block gas limit:", block.gasLimit.toString());

  // Load artifacts
  const v2Path = process.env.V2_ARTIFACT || "artifacts/contracts/factory/TPIXTokenFactoryV2.sol/TPIXTokenFactoryV2.json";
  const nftPath = process.env.NFT_ARTIFACT || "artifacts/contracts/factory/TPIXNFTFactory.sol/TPIXNFTFactory.json";
  console.log("V2 artifact:", v2Path);
  console.log("NFT artifact:", nftPath);
  const v2Art = JSON.parse(readFileSync(v2Path, "utf8"));
  const nftArt = JSON.parse(readFileSync(nftPath, "utf8"));

  console.log("V2 bytecode size:", Math.round(v2Art.bytecode.length / 2), "bytes");
  console.log("NFT bytecode size:", Math.round(nftArt.bytecode.length / 2), "bytes");

  // Deploy TPIXTokenFactoryV2
  console.log("\n[1/2] Deploying TPIXTokenFactoryV2...");
  try {
    const gasLimit = Number(block.gasLimit);
    console.log("  Using gasLimit:", gasLimit);

    const v2Factory = new ethers.ContractFactory(v2Art.abi, v2Art.bytecode, wallet);

    // Step-by-step deploy for debugging
    console.log("  Step 1: Getting deploy transaction...");
    const v2DeployTx = await v2Factory.getDeployTransaction({ gasPrice: 0, gasLimit });
    console.log("  Deploy tx data length:", v2DeployTx.data.length, "chars");

    console.log("  Step 2: Getting nonce...");
    const nonce = await wallet.getNonce();
    console.log("  Nonce:", nonce);

    console.log("  Step 3: Populating transaction...");
    const v2PopTx = await wallet.populateTransaction({ ...v2DeployTx, nonce });
    console.log("  Gas limit in tx:", v2PopTx.gasLimit?.toString());

    console.log("  Step 4: Signing transaction...");
    const v2SignedTx = await wallet.signTransaction(v2PopTx);
    console.log("  Signed tx length:", v2SignedTx.length, "chars");

    console.log("  Step 5: Broadcasting transaction...");
    const v2TxResponse = await provider.broadcastTransaction(v2SignedTx);
    console.log("  Tx hash:", v2TxResponse.hash);

    console.log("  Step 6: Waiting for confirmation...");
    const v2Receipt = await v2TxResponse.wait();
    const v2Addr = v2Receipt.contractAddress;
    console.log("  TPIXTokenFactoryV2:", v2Addr);
    console.log("  Gas used:", v2Receipt.gasUsed.toString());

    // Deploy TPIXNFTFactory
    console.log("\n[2/2] Deploying TPIXNFTFactory...");
    const nftFactory = new ethers.ContractFactory(nftArt.abi, nftArt.bytecode, wallet);
    const nftDeployTx = await nftFactory.getDeployTransaction({ gasPrice: 0, gasLimit });
    console.log("  NFT deploy tx data length:", nftDeployTx.data.length);
    const nft = await nftFactory.deploy({ gasPrice: 0, gasLimit });
    const nftReceipt = await nft.deploymentTransaction().wait();
    const nftAddr = await nft.getAddress();
    console.log("  TPIXNFTFactory:", nftAddr);
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
    console.log("\nTOKEN_FACTORY_V2_ADDRESS=" + v2Addr);
    console.log("NFT_FACTORY_ADDRESS=" + nftAddr);
  } catch (err) {
    console.log("Deploy failed:", err.message);
    if (err.info) console.log("Info:", JSON.stringify(err.info));
    if (err.error) console.log("Error detail:", err.error.message || err.error);
    console.log("Full error:", JSON.stringify(err, Object.getOwnPropertyNames(err)));
    process.exit(1);
  }
}

main().catch((e) => {
  console.log("Fatal:", e.message || e);
  console.log("Stack:", e.stack || "no stack");
  process.exit(1);
});
