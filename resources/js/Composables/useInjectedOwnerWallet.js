/**
 * TPIX TRADE — กระเป๋าในเบราว์เซอร์สำหรับงานเจ้าของสัญญา (หน้าแอดมิน)
 *
 * ทำไมไม่ใช้ walletStore ของหน้าเว็บ:
 *   walletStore ผูกกระเป๋ากับบัญชีผู้ใช้ของเว็บ (ลงทะเบียน + เซ็นพิสูจน์เข้า session)
 *   กุญแจ owner ของสัญญาไม่ควรถูกผูกเป็น "ผู้ใช้" ของเว็บ — ที่นี่แค่ขอ signer มาเซ็น tx เท่านั้น
 *
 * ใช้ได้กับกระเป๋าที่ฉีด window.ethereum (MetaMask / Rabby / ฯลฯ)
 *
 * Developed by Xman Studio
 */
import { ref, shallowRef, onUnmounted } from 'vue';

let ethersPromise = null;
export function loadEthers() {
    if (!ethersPromise) ethersPromise = import('ethers').catch((e) => { ethersPromise = null; throw e; });
    return ethersPromise;
}

export function useInjectedOwnerWallet({ chainId, rpcUrl, chainName = 'TPIX Chain', explorerUrl = 'https://explorer.tpix.online' }) {
    const address = ref('');
    const currentChainId = ref(null);
    const connecting = ref(false);
    const provider = shallowRef(null);
    let listening = false;

    const injected = () => (typeof window !== 'undefined' ? window.ethereum : null);

    function onAccounts(accounts) {
        address.value = Array.isArray(accounts) && accounts[0] ? String(accounts[0]).toLowerCase() : '';
    }
    function onChain(id) {
        currentChainId.value = Number.parseInt(id, 16);
    }

    async function connect() {
        const eth = injected();
        if (!eth) throw new Error('NO_INJECTED_WALLET');
        connecting.value = true;
        try {
            const { BrowserProvider } = await loadEthers();
            const accounts = await eth.request({ method: 'eth_requestAccounts' });
            onAccounts(accounts);
            onChain(await eth.request({ method: 'eth_chainId' }));
            provider.value = new BrowserProvider(eth);
            if (!listening) {
                eth.on?.('accountsChanged', onAccounts);
                eth.on?.('chainChanged', onChain);
                listening = true;
            }
        } finally {
            connecting.value = false;
        }
    }

    async function ensureChain() {
        const eth = injected();
        if (!eth) throw new Error('NO_INJECTED_WALLET');
        if (currentChainId.value === chainId) return;
        const hexId = '0x' + chainId.toString(16);
        try {
            await eth.request({ method: 'wallet_switchEthereumChain', params: [{ chainId: hexId }] });
        } catch (e) {
            if (e?.code !== 4902) throw e;
            await eth.request({
                method: 'wallet_addEthereumChain',
                params: [{
                    chainId: hexId,
                    chainName,
                    nativeCurrency: { name: 'TPIX', symbol: 'TPIX', decimals: 18 },
                    rpcUrls: [rpcUrl],
                    blockExplorerUrls: [explorerUrl],
                }],
            });
        }
        onChain(await eth.request({ method: 'eth_chainId' }));
        if (currentChainId.value !== chainId) throw new Error('WRONG_CHAIN');
    }

    /**
     * ส่ง tx จากกระเป๋าที่ "เป็นบัญชีเดียวกับตอนกดปุ่ม" เท่านั้น
     * ผู้ใช้สลับบัญชีระหว่างรอ = ยกเลิกก่อนส่ง ไม่ปล่อยให้บัญชีอื่นเซ็นแทน
     *
     * @param {string} expected  บัญชีที่ต้องเป็นผู้เซ็น (owner ของสัญญา)
     * @param {{to:string,data:string}} call
     */
    async function sendAs(expected, call) {
        const guardAddress = address.value;
        if (!guardAddress) throw new Error('NO_INJECTED_WALLET');
        if (guardAddress.toLowerCase() !== String(expected).toLowerCase()) throw new Error('NOT_OWNER');

        await ensureChain();
        const { BrowserProvider } = await loadEthers();
        const signer = await new BrowserProvider(injected()).getSigner();
        const signerAddress = (await signer.getAddress()).toLowerCase();
        if (signerAddress !== guardAddress || address.value !== guardAddress) throw new Error('WALLET_SWITCHED');

        // type 0 + gasPrice 0: polygon-edge 0.9 ไม่รู้จัก tx แบบ 1/2 และเชนนี้ค่าแก๊ส 0
        return signer.sendTransaction({ to: call.to, data: call.data, gasPrice: 0n, type: 0 });
    }

    /** รอ receipt จาก RPC ของเชนตรง ๆ — ไม่พึ่ง provider ของกระเป๋าที่อาจชี้เชนอื่น */
    async function waitReceipt(hash, timeoutMs = 90_000) {
        const { JsonRpcProvider, Network } = await loadEthers();
        const rpc = new JsonRpcProvider(rpcUrl, Network.from(chainId), { staticNetwork: true });
        return rpc.waitForTransaction(hash, 1, timeoutMs);
    }

    onUnmounted(() => {
        const eth = injected();
        if (listening && eth) {
            eth.removeListener?.('accountsChanged', onAccounts);
            eth.removeListener?.('chainChanged', onChain);
            listening = false;
        }
    });

    return { address, currentChainId, connecting, provider, connect, ensureChain, sendAs, waitReceipt };
}
