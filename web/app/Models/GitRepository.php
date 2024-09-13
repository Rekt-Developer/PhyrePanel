<?php

namespace App\Models;

use App\GitClient;
use App\Models\Scopes\CustomerDomainScope;
use App\Models\Scopes\CustomerHostingSubscriptionScope;
use App\ShellApi;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use function Psy\sh;

class GitRepository extends Model
{
    use HasFactory;

    public $timestamps = true;

    const STATUS_PENDING = 'pending';

    const STATUS_CLONING = 'cloning';
    const STATUS_CLONED = 'cloned';
    const STATUS_FAILED = 'failed';

    const STATUS_PULLING = 'pulling';

    protected $fillable = [
        'name',
        'url',
        'branch',
        'tag',
        'clone_from',
        'last_commit_hash',
        'last_commit_message',
        'last_commit_date',
        'status',
        'status_message',
        'dir',
        'domain_id',
        'git_ssh_key_id',
    ];

    /**
     * The "booted" method of the model.
     */
    protected static function booted(): void
    {
        static::addGlobalScope(new CustomerDomainScope());
    }

    public static function boot()
    {
        parent::boot();

        static::created(function ($model) {
            $model->clone();
        });

        static::deleting(function ($model) {
            $projectDir = $model->domain->domain_root . '/' . $model->dir;
            ShellApi::safeDelete($projectDir,[
                $model->domain->domain_root . '/',
            ]);
        });
    }

    public function domain()
    {
        return $this->belongsTo(Domain::class);
    }

    public function pull()
    {
        $this->status = self::STATUS_PULLING;
        $this->save();

    }

    public function clone()
    {
        $this->status = self::STATUS_CLONING;
        $this->save();

        $findDomain = Domain::find($this->domain_id);
        if (!$findDomain) {
            $this->status = self::STATUS_FAILED;
            $this->status_message = 'Domain not found';
            $this->save();
            return;
        }

        $findHostingSubscription = HostingSubscription::find($findDomain->hosting_subscription_id);
        if (!$findHostingSubscription) {
            $this->status = self::STATUS_FAILED;
            $this->status_message = 'Hosting Subscription not found';
            $this->save();
            return;
        }


        $projectDir = $findDomain->domain_root . '/' . $this->dir;

        $privateKeyFile = null;

        $gitSSHKey = GitSshKey::find($this->git_ssh_key_id);
        if ($gitSSHKey) {
            $sshPath = '/home/'.$findHostingSubscription->system_username .'/.ssh';
            $privateKeyFile = $sshPath.'/id_rsa_'. $gitSSHKey->id;
            $publicKeyFile = $sshPath.'/id_rsa_'.$gitSSHKey->id.'.pub';

            if (!is_dir($sshPath)) {
                shell_exec('mkdir -p ' . $sshPath);
                shell_exec('chown '.$findHostingSubscription->system_username.':'.$findHostingSubscription->system_username.' -R ' . dirname($sshPath));
                shell_exec('chmod 0700 ' . dirname($sshPath));
            }

            if (!file_exists($privateKeyFile)) {
                file_put_contents($privateKeyFile, $gitSSHKey->private_key);

                shell_exec('chown '.$findHostingSubscription->system_username.':'.$findHostingSubscription->system_username.' ' . $privateKeyFile);
                shell_exec('chmod 0400 ' . $privateKeyFile);

            }

            if (!file_exists($publicKeyFile)) {
                file_put_contents($publicKeyFile, $gitSSHKey->public_key);
                shell_exec('chown '.$findHostingSubscription->system_username.':'.$findHostingSubscription->system_username.' ' . $publicKeyFile);
                shell_exec('chmod 0400 ' . $publicKeyFile);
            }
        }

        $gitSSHUrl = GitClient::parseGitUrl($this->url);
        if (!isset($gitSSHUrl['provider'])) {
            $this->status = self::STATUS_FAILED;
            $this->status_message = 'Provider not found';
            $this->save();
            return;
        }

        $cloneUrl = 'git@'.$gitSSHUrl['provider'].':'.$gitSSHUrl['owner'].'/'.$gitSSHUrl['name'].'.git';

        $shellFile = '/tmp/git-clone-' . $this->id . '.sh';
        $shellLog = '/tmp/git-clone-' . $this->id . '.log';

        $shellContent = view('actions.git.clone-repo', [
            'gitProvider' => $gitSSHUrl['provider'],
            'systemUsername' => $findHostingSubscription->system_username,
            'gitRepositoryId' => $this->id,
            'cloneUrl' => $cloneUrl,
            'projectDir' => $projectDir,
            'privateKeyFile' => $privateKeyFile,
        ])->render();

        file_put_contents($shellFile, $shellContent);

        shell_exec('chmod +x ' . $shellFile);
        shell_exec('bash '.$shellFile.' >> ' . $shellLog . ' &');

    }

}
