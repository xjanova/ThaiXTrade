# TPIX TRADE - SSH Key Setup for GitHub Actions

This guide walks you through setting up SSH key authentication for automated deployments via GitHub Actions.

## Step 1: Generate SSH Key

On your **local machine**, run:

```bash
ssh-keygen -t ed25519 -C "github-actions-thaixtrade" -f ~/.ssh/thaixtrade_deploy
```

This creates:
- `~/.ssh/thaixtrade_deploy` (private key)
- `~/.ssh/thaixtrade_deploy.pub` (public key)

## Step 2: Add Public Key to Server

Copy the public key to your server:

```bash
ssh-copy-id -i ~/.ssh/thaixtrade_deploy.pub user@your-server
```

Or manually:

```bash
# On the server
echo "YOUR_PUBLIC_KEY_CONTENT" >> ~/.ssh/authorized_keys
chmod 600 ~/.ssh/authorized_keys
chmod 700 ~/.ssh
```

## Step 3: Test SSH Connection

```bash
ssh -i ~/.ssh/thaixtrade_deploy user@your-server "echo 'Connection successful'"
```

## Step 4: Configure GitHub Secrets

Go to your repository: **Settings > Secrets and variables > Actions**

Add these repository secrets:

| Secret Name | Value | Required |
|------------|-------|----------|
| `SSH_HOST` | Your server IP or hostname | Yes |
| `SSH_USER` | SSH username | Yes |
| `SSH_PRIVATE_KEY` | Contents of `thaixtrade_deploy` (private key) | Yes |
| `SSH_PORT` | SSH port (default: 22) | Optional |
| `DEPLOY_PATH` | Application path (default: /home/admin/domains/tpix.online) | Optional |
| `APP_URL` | Application URL for health checks | Optional |

### How to copy the private key:

```bash
cat ~/.ssh/thaixtrade_deploy
```

Copy the **entire output** including:
```
-----BEGIN OPENSSH PRIVATE KEY-----
...
-----END OPENSSH PRIVATE KEY-----
```

## Step 5: Test Deployment

1. Go to **Actions** tab in your repository
2. Select **Auto Deploy to Production**
3. Click **Run workflow**
4. Watch the deployment progress

---

## Troubleshooting

### Connection refused

```bash
# Check SSH service is running
sudo systemctl status sshd

# Check firewall
sudo ufw status
sudo ufw allow 22
```

### Permission denied

```bash
# Verify key permissions on server
chmod 600 ~/.ssh/authorized_keys
chmod 700 ~/.ssh

# Check SSH config allows key auth
sudo grep -i "PubkeyAuthentication" /etc/ssh/sshd_config
# Should be: PubkeyAuthentication yes
```

### Host key verification failed

Add this to GitHub Actions step:

```yaml
- name: Add host key
  run: |
    mkdir -p ~/.ssh
    ssh-keyscan -p ${{ secrets.SSH_PORT || 22 }} ${{ secrets.SSH_HOST }} >> ~/.ssh/known_hosts
```

### Deployment path not writable

```bash
# On server, ensure the deploy user owns the project directory
sudo chown -R deploy:deploy /home/admin/domains/tpix.online
```

---

## Security Best Practices

1. **Use Ed25519 keys** (more secure than RSA)
2. **Use a dedicated deploy user** with limited permissions
3. **Restrict SSH key** to specific commands if possible
4. **Rotate keys** periodically
5. **Never commit** private keys to the repository
6. **Use GitHub's encrypted secrets** for all sensitive data
