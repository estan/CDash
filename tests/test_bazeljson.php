<?php
require_once dirname(__FILE__) . '/cdash_test_case.php';
require_once 'include/common.php';
require_once 'include/pdo.php';

class BazelJSONTestCase extends KWWebTestCase
{
    public function __construct()
    {
        parent::__construct();
        $this->PDO = get_link_identifier()->getPdo();
        $this->BuildId = 0;
    }

    public function __destruct()
    {
        if ($this->BuildId > 0) {
        }
    }

    public function testBazelJSON()
    {

        // Submit testing data.
        $buildid = $this->submit_data('InsightExample', 'BazelJSON',
            '0a9b0aeeb73618cd10d6e1bee221fd71',
            dirname(__FILE__) . '/data/Bazel/bazel_BEP.json');
        if (!$buildid) {
            return false;
        }

        // Validate the build.
        $stmt = $this->PDO->query(
                "SELECT builderrors, buildwarnings, testfailed, testpassed
                FROM build WHERE id = $buildid");
        $row = $stmt->fetch();

        $answer_key = [
            'builderrors' => 1,
            'buildwarnings' => 2,
            'testfailed' => 1,
            'testpassed' => 1
        ];
        foreach ($answer_key as $key => $expected) {
            $found = $row[$key];
            if ($found != $expected) {
                $this->fail("Expected $expected for $key but found $found");
            }
        }

        // Cleanup.
        remove_build($buildid);
    }

    public function testBazelSubProjs()
    {
        // Create a new project.
        $settings = [
            'Name' => 'BazelSubProj',
            'Public' => 1
        ];
        $projectid = $this->createProject($settings);
        if ($projectid < 1) {
            $this->fail('Failed to create project');
        }
        $project = new Project();
        $project->Id = $projectid;

        // Setup subprojects.
        $parentid = $this->submit_data('BazelSubProj', 'SubProjectDirectories',
            '9e909746b706562eb263262a1496f202',
            dirname(__FILE__) . '/data/Bazel/subproj/subproj_list.txt');
        if (!$parentid) {
            return false;
        }

        // Submit build and test data.
        $parentid2 = $this->submit_data('BazelSubProj', 'BazelJSON',
            'a860786f23529d62472ba363525cd2f3',
            dirname(__FILE__) . '/data/Bazel/subproj/subproj_build.json');
        if (!$parentid2 || $parentid !== $parentid2) {
            $this->fail("parentid mismatch $parentid vs $parentid2");
            return false;
        }
        $parentid3 = $this->submit_data('BazelSubProj', 'BazelJSON',
            'c261e5014fddb72b372b85449be3301e',
            dirname(__FILE__) . '/data/Bazel/subproj/subproj_test.json');
        if (!$parentid3 || $parentid !== $parentid3) {
            $this->fail("parentid mismatch $parentid vs $parentid3");
            return false;
        }

        // Validate the parent build.
        $stmt = $this->PDO->query(
                "SELECT builderrors, buildwarnings, testfailed, testpassed
                FROM build WHERE id = $parentid");
        $row = $stmt->fetch();
        $answer_key = [
            'builderrors' => 0,
            'buildwarnings' => 2,
            'testfailed' => 1,
            'testpassed' => 1
        ];
        foreach ($answer_key as $key => $expected) {
            $found = $row[$key];
            if ($found != $expected) {
                $this->fail("Expected $expected for $key but found $found");
            }
        }


        // Validate the children builds.
        $stmt = $this->PDO->query(
                "SELECT builderrors, buildwarnings, testfailed, testpassed,
                        sp.name
                FROM build b
                JOIN subproject2build sp2b ON sp2b.buildid = b.id
                JOIN subproject sp ON sp.id = sp2b.subprojectid
                WHERE parentid = $parentid");
        while ($row = $stmt->fetch()) {
            $subproject_name = $row['name'];
            $answer_key = [];
            switch ($row['name']) {
                case 'subproj1':
                    $answer_key = [
                        'builderrors' => 0,
                        'buildwarnings' => 1,
                        'testfailed' => 0,
                        'testpassed' => 1
                    ];
                    break;
                case 'subproj2':
                    $answer_key = [
                        'builderrors' => 0,
                        'buildwarnings' => 1,
                        'testfailed' => 1,
                        'testpassed' => 0
                    ];
                    break;
                default:
                    $this->fail("Unexpected subproject $subproject_name");
                    break;
            }
            foreach ($answer_key as $key => $expected) {
                $found = $row[$key];
                if ($found != $expected) {
                    $this->fail("Expected $expected for $key but found $found for subproject $subproject_name");
                }
            }
        }

        // Cleanup.
        remove_project_builds($projectid);
        $project->Delete();
    }

    private function submit_data($project_name, $upload_type, $md5, $file_path)
    {
        $fields = [
            'project' => $project_name,
            'build' => 'bazel_json',
            'site' => 'localhost',
            'stamp' => '20170823-1835-Experimental',
            'starttime' => '1503513355',
            'endtime' => '1503513355',
            'track' => 'Experimental',
            'type' => $upload_type,
            'datafilesmd5[0]=' => $md5];
        $client = new GuzzleHttp\Client();
        global $CDASH_BASE_URL;
        try {
            $response = $client->request(
                'POST',
                $CDASH_BASE_URL . '/submit.php',
                [
                    'form_params' => $fields
                ]
            );
        } catch (GuzzleHttp\Exception\ClientException $e) {
            $this->fail('POST submit failed: ' . $e->getMessage());
            return false;
        }

        // Parse buildid for subsequent PUT request.
        $response_array = json_decode($response->getBody(), true);
        $buildid = $response_array['buildid'];

        // Do the PUT request.
        $file_name = basename($file_path);
        $puturl = $this->url . "/submit.php?type=$upload_type&md5=$md5&filename=$file_name&buildid=$buildid";
        if ($this->uploadfile($puturl, $file_path) === false) {
            $this->fail("Upload failed for $file_name");
            return false;
        }
        return $buildid;
    }
}