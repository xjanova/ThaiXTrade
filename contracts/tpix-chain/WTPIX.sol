// SPDX-License-Identifier: MIT
pragma solidity ^0.8.20;

/**
 * WTPIX — Wrapped TPIX (ERC-20)
 * Native TPIX wrapper สำหรับ TPIX Chain (Chain ID: 4289)
 * เหมือน WETH บน Ethereum — แปลง native TPIX ↔ ERC-20 WTPIX
 * Gas Price = 0 (Free) บน TPIX Chain
 * Developed by Xman Studio
 */
contract WTPIX {
    string public name     = "Wrapped TPIX";
    string public symbol   = "WTPIX";
    uint8  public decimals = 18;

    event Approval(address indexed src, address indexed guy, uint wad);
    event Transfer(address indexed src, address indexed dst, uint wad);
    event Deposit(address indexed dst, uint wad);
    event Withdrawal(address indexed src, uint wad);

    mapping(address => uint)                       public balanceOf;
    mapping(address => mapping(address => uint))   public allowance;

    /// @notice Wrap native TPIX → WTPIX (ERC-20)
    receive() external payable {
        deposit();
    }

    /// @notice Wrap native TPIX → WTPIX (ERC-20)
    function deposit() public payable {
        balanceOf[msg.sender] += msg.value;
        emit Deposit(msg.sender, msg.value);
    }

    /// @notice Unwrap WTPIX → native TPIX
    function withdraw(uint wad) public {
        require(balanceOf[msg.sender] >= wad, "WTPIX: insufficient balance");
        balanceOf[msg.sender] -= wad;
        payable(msg.sender).transfer(wad);
        emit Withdrawal(msg.sender, wad);
    }

    function totalSupply() public view returns (uint) {
        return address(this).balance;
    }

    function approve(address guy, uint wad) public returns (bool) {
        allowance[msg.sender][guy] = wad;
        emit Approval(msg.sender, guy, wad);
        return true;
    }

    function transfer(address dst, uint wad) public returns (bool) {
        return transferFrom(msg.sender, dst, wad);
    }

    function transferFrom(address src, address dst, uint wad) public returns (bool) {
        require(balanceOf[src] >= wad, "WTPIX: insufficient balance");

        if (src != msg.sender && allowance[src][msg.sender] != type(uint).max) {
            require(allowance[src][msg.sender] >= wad, "WTPIX: insufficient allowance");
            allowance[src][msg.sender] -= wad;
        }

        balanceOf[src] -= wad;
        balanceOf[dst] += wad;

        emit Transfer(src, dst, wad);
        return true;
    }
}
