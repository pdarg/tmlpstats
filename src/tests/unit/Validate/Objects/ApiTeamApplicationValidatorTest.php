<?php
namespace TmlpStats\Tests\Unit\Validate\Objects;

use Carbon\Carbon;
use TmlpStats\Domain\TeamApplication;
use TmlpStats\Tests\Unit\Traits;
use TmlpStats\Tests\Unit\Validate\ApiValidatorTestAbstract;
use TmlpStats\Validate\Objects\ApiTeamApplicationValidator;

class ApiTeamApplicationValidatorTest extends ApiValidatorTestAbstract
{
    use Traits\MocksSettings;

    protected $instantiateApp = true;
    protected $testClass = ApiTeamApplicationValidator::class;

    protected $messageTemplate = [
        'id' => 'placeholder',
        'level' => 'error',
        'reference' => [
            'id' => null,
            'type' => 'TeamApplication',
        ],
    ];

    public function setUp()
    {
        parent::setUp();

        // When using Settings, we need center to be null to avoid db lookups
        $this->statsReport->center = null;

        $this->setSetting('travelDueByDate', 'classroom2Date');
        $this->setSetting('bouncedEmails', '');

        $this->dataTemplate = [
            'firstName' => 'Keith',
            'lastName' => 'Stone',
            'email' => 'unit_test@tmlpstats.com',
            'center' => 1234,
            'teamYear' => 1,
            'regDate' => Carbon::parse('2016-08-22'),
            'isReviewer' => false,
            'phone' => '555-555-5555',
            'tmlpRegistration' => 1234,
            'appOutDate' => Carbon::parse('2016-08-23'),
            'appInDate' => Carbon::parse('2016-08-24'),
            'apprDate' => Carbon::parse('2016-08-25'),
            'wdDate' => null,
            'withdrawCodeId' => null,
            'committedTeamMember' => 1234,
            'incomingQuarter' => 1234,
            'comment' => 'asdf qwerty',
            'travel' => true,
            'room' => true,
        ];
    }

    public function tearDown()
    {
        parent::tearDown();

        $this->clearSettings();
    }

    /**
     * @dataProvider providerRun
     */
    public function testRun($data, $expectedMessages, $expectedResult)
    {
        $validator = $this->getValidatorMock($data, ['isStartingNextQuarter', 'isTimeToCheckTravel']);

        $validator->expects($this->any())
                  ->method('isStartingNextQuarter')
                  ->willReturn(true);

        $validator->expects($this->any())
                  ->method('isTimeToCheckTravel')
                  ->willReturn(false);

        $result = $validator->run($this->getTeamApplication($data));

        $this->assertMessages($expectedMessages, $validator->getMessages());
        $this->assertEquals($expectedResult, $result);
    }

    public function providerRun()
    {
        return [
            // Test Required
            [
                [
                    'firstName' => null,
                    'lastName' => null,
                    'email' => null,
                    'center' => null,
                    'teamYear' => null,
                    'regDate' => null,
                    'isReviewer' => null,
                    'phone' => null,
                    'tmlpRegistration' => null,
                    'appOutDate' => null,
                    'appInDate' => null,
                    'apprDate' => null,
                    'wdDate' => null,
                    'committedTeamMember' => null,
                    'withdrawCodeId' => null,
                    'incomingQuarter' => null,
                    'comment' => null,
                    'travel' => null,
                    'room' => null,
                ],
                [
                    $this->getMessageData($this->messageTemplate, [
                        'id' => 'GENERAL_MISSING_VALUE',
                        'reference.field' => 'firstName',
                    ]),
                    $this->getMessageData($this->messageTemplate, [
                        'id' => 'GENERAL_MISSING_VALUE',
                        'reference.field' => 'lastName',
                    ]),
                    $this->getMessageData($this->messageTemplate, [
                        'id' => 'GENERAL_MISSING_VALUE',
                        'reference.field' => 'teamYear',
                    ]),
                    $this->getMessageData($this->messageTemplate, [
                        'id' => 'GENERAL_MISSING_VALUE',
                        'reference.field' => 'regDate',
                    ]),
                    $this->getMessageData($this->messageTemplate, [
                        'id' => 'GENERAL_MISSING_VALUE',
                        'reference.field' => 'incomingQuarterId',
                    ]),
                    $this->getMessageData($this->messageTemplate, [
                        'id' => 'TEAMAPP_NO_COMMITTED_TEAM_MEMBER',
                        'reference.field' => 'committedTeamMemberId',
                        'level' => 'warning',
                    ]),
                ],
                false,
            ],
            // Test Valid (Variable set 1)
            [
                [
                ],
                [],
                true,
            ],
            // Test Valid (Variable set 2)
            [
                [
                    'wdDate' => Carbon::parse('2016-08-26'),
                    'withdrawCodeId' => 1234,
                    'teamYear' => 2,
                    'isReviewer' => true,
                    'travel' => false,
                    'room' => false,
                ],
                [],
                true,
            ],

            // Test Invalid First Name
            [
                [
                    'firstName' => '',
                ],
                [
                    $this->getMessageData($this->messageTemplate, [
                        'id' => 'GENERAL_MISSING_VALUE',
                        'reference.field' => 'firstName',
                    ]),
                ],
                false,
            ],
            // Test Invalid Last Name
            [
                [
                    'lastName' => '',
                ],
                [
                    $this->getMessageData($this->messageTemplate, [
                        'id' => 'GENERAL_MISSING_VALUE',
                        'reference.field' => 'lastName',
                    ]),
                ],
                false,
            ],
            // Test invalid TeamYear
            [
                [
                    'teamYear' => 3,
                ],
                [
                    $this->getMessageData($this->messageTemplate, [
                        'id' => 'GENERAL_INVALID_VALUE',
                        'reference.field' => 'teamYear',
                    ]),
                ],
                false,
            ],
        ];
    }

    /**
     * @dataProvider providerValidateApprovalProcess
     */
    public function testValidateApprovalProcess($data, $expectedMessages, $expectedResult)
    {
        $validator = $this->getValidatorMock($data);
        $result = $validator->run($this->getTeamApplication($data));

        $this->assertMessages($expectedMessages, $validator->getMessages());
        $this->assertEquals($expectedResult, $result);
    }

    public function providerValidateApprovalProcess()
    {
        return [
            // Withdraw and no other steps complete
            [
                [
                    'appOutDate' => null,
                    'appInDate' => null,
                    'apprDate' => null,
                    'wdDate' => Carbon::parse('2016-08-22'),
                    'withdrawCodeId' => 1234,
                ],
                [],
                true,
            ],
            // Withdraw and all steps complete
            [
                [
                    'appOutDate' => Carbon::parse('2016-08-22'),
                    'appInDate' => Carbon::parse('2016-08-23'),
                    'apprDate' => Carbon::parse('2016-08-27'),
                    'wdDate' => Carbon::parse('2016-08-28'),
                    'withdrawCodeId' => 1234,
                ],
                [],
                true,
            ],
            // Withdraw and missing wd
            [
                [
                    'wdDate' => Carbon::parse('2016-08-28'),
                    'withdrawCodeId' => null,
                ],
                [
                    $this->getMessageData($this->messageTemplate, [
                        'id' => 'TEAMAPP_WD_CODE_MISSING',
                        'reference.field' => 'withdrawCodeId',
                    ]),
                ],
                false,
            ],
            // Withdraw and missing date
            [
                [
                    'wdDate' => null,
                    'withdrawCodeId' => 1234,
                ],
                [
                    $this->getMessageData($this->messageTemplate, [
                        'id' => 'TEAMAPP_WD_DATE_MISSING',
                        'reference.field' => 'wdDate',
                    ]),
                ],
                false,
            ],

            // Approved
            [
                [
                    'appOutDate' => Carbon::parse('2016-08-22'),
                    'appInDate' => Carbon::parse('2016-08-23'),
                    'apprDate' => Carbon::parse('2016-08-27'),
                ],
                [],
                true,
            ],
            // Approved and missing appInDate
            [
                [
                    'appOutDate' => Carbon::parse('2016-08-22'),
                    'appInDate' => null,
                    'apprDate' => Carbon::parse('2016-08-27'),
                ],
                [
                    $this->getMessageData($this->messageTemplate, [
                        'id' => 'TEAMAPP_APPIN_DATE_MISSING',
                        'reference.field' => 'appInDate',
                    ]),
                    $this->getMessageData($this->messageTemplate, [
                        'id' => 'TEAMAPP_APPIN_LATE',
                        'reference.field' => 'appInDate',
                        'level' => 'warning',
                    ]),
                ],
                false,
            ],
            // Approved and missing appOutDate
            [
                [
                    'appOutDate' => null,
                    'appInDate' => Carbon::parse('2016-08-23'),
                    'apprDate' => Carbon::parse('2016-08-27'),
                ],
                [
                    $this->getMessageData($this->messageTemplate, [
                        'id' => 'TEAMAPP_APPOUT_DATE_MISSING',
                        'reference.field' => 'appOutDate',
                    ]),
                    $this->getMessageData($this->messageTemplate, [
                        'id' => 'TEAMAPP_APPOUT_LATE',
                        'reference.field' => 'appOutDate',
                        'level' => 'warning',
                    ]),
                ],
                false,
            ],

            // App In
            [
                [
                    'appOutDate' => Carbon::parse('2016-08-23'),
                    'appInDate' => Carbon::parse('2016-08-30'),
                    'apprDate' => null,
                ],
                [],
                true,
            ],
            // App In and missing appOutDate
            [
                [
                    'appOutDate' => null,
                    'appInDate' => Carbon::parse('2016-08-27'),
                    'apprDate' => null,
                ],
                [
                    $this->getMessageData($this->messageTemplate, [
                        'id' => 'TEAMAPP_APPOUT_DATE_MISSING',
                        'reference.field' => 'appOutDate',
                    ]),
                    $this->getMessageData($this->messageTemplate, [
                        'id' => 'TEAMAPP_APPOUT_LATE',
                        'reference.field' => 'appOutDate',
                        'level' => 'warning',
                    ]),
                ],
                false,
            ],

            // App Out
            [
                [
                    'regDate' => Carbon::parse('2016-08-26'),
                    'appOutDate' => Carbon::parse('2016-08-29'),
                    'appInDate' => null,
                    'apprDate' => null,
                ],
                [],
                true,
            ],

            // No approval steps complete
            [
                [
                    'appOutDate' => null,
                    'appInDate' => null,
                    'apprDate' => null,
                ],
                [
                    $this->getMessageData($this->messageTemplate, [
                        'id' => 'TEAMAPP_APPOUT_LATE',
                        'reference.field' => 'appOutDate',
                        'level' => 'warning',
                    ]),
                ],
                true,
            ],
            // Missing committed team member
            [
                [
                    'committedTeamMember' => null,
                ],
                [
                    $this->getMessageData($this->messageTemplate, [
                        'id' => 'TEAMAPP_NO_COMMITTED_TEAM_MEMBER',
                        'reference.field' => 'committedTeamMemberId',
                        'level' => 'warning',
                    ]),
                ],
                true,
            ],
        ];
    }

    /**
     * @dataProvider providerValidateDates
     */
    public function testValidateDates($data, $expectedMessages, $expectedResult)
    {
        $validator = $this->getValidatorMock($data);
        $result = $validator->run($this->getTeamApplication($data));

        $this->assertMessages($expectedMessages, $validator->getMessages());
        $this->assertEquals($expectedResult, $result);
    }

    public function providerValidateDates()
    {
        return [
            // Withdraw date OK
            [
                [
                    'regDate' => Carbon::parse('2016-08-22'),
                    'appOutDate' => null,
                    'appInDate' => null,
                    'apprDate' => null,
                    'wdDate' => Carbon::parse('2016-08-25'),
                    'withdrawCodeId' => 1234,
                ],
                [],
                true,
            ],
            // Withdraw and wdDate before regDate
            [
                [
                    'regDate' => Carbon::parse('2016-08-22'),
                    'appOutDate' => null,
                    'appInDate' => null,
                    'apprDate' => null,
                    'wdDate' => Carbon::parse('2016-08-21'),
                    'withdrawCodeId' => 1234,
                ],
                [
                    $this->getMessageData($this->messageTemplate, [
                        'id' => 'TEAMAPP_WD_DATE_BEFORE_REG_DATE',
                        'reference.field' => 'wdDate',
                    ]),
                ],
                false,
            ],
            // Withdraw and approve dates OK
            [
                [
                    'regDate' => Carbon::parse('2016-08-22'),
                    'appOutDate' => null,
                    'appInDate' => null,
                    'apprDate' => Carbon::parse('2016-08-25'),
                    'wdDate' => Carbon::parse('2016-08-26'),
                    'withdrawCodeId' => 1234,
                ],
                [],
                true,
            ],
            // Withdraw and wdDate before apprDate
            [
                [
                    'regDate' => Carbon::parse('2016-08-22'),
                    'appOutDate' => null,
                    'appInDate' => null,
                    'apprDate' => Carbon::parse('2016-08-25'),
                    'wdDate' => Carbon::parse('2016-08-24'),
                    'withdrawCodeId' => 1234,
                ],
                [
                    $this->getMessageData($this->messageTemplate, [
                        'id' => 'TEAMAPP_WD_DATE_BEFORE_APPR_DATE',
                        'reference.field' => 'wdDate',
                    ]),
                ],
                false,
            ],
            // Withdraw and appIn dates OK
            [
                [
                    'regDate' => Carbon::parse('2016-08-22'),
                    'appOutDate' => null,
                    'appInDate' => Carbon::parse('2016-08-24'),
                    'apprDate' => null,
                    'wdDate' => Carbon::parse('2016-08-26'),
                    'withdrawCodeId' => 1234,
                ],
                [],
                true,
            ],
            // Withdraw and wdDate before appInDate
            [
                [
                    'regDate' => Carbon::parse('2016-08-22'),
                    'appOutDate' => null,
                    'appInDate' => Carbon::parse('2016-08-24'),
                    'apprDate' => null,
                    'wdDate' => Carbon::parse('2016-08-23'),
                    'withdrawCodeId' => 1234,
                ],
                [
                    $this->getMessageData($this->messageTemplate, [
                        'id' => 'TEAMAPP_WD_DATE_BEFORE_APPIN_DATE',
                        'reference.field' => 'wdDate',
                    ]),
                ],
                false,
            ],
            // Withdraw and appOut dates OK
            [
                [
                    'regDate' => Carbon::parse('2016-08-22'),
                    'appOutDate' => Carbon::parse('2016-08-23'),
                    'appInDate' => null,
                    'apprDate' => null,
                    'wdDate' => Carbon::parse('2016-08-26'),
                    'withdrawCodeId' => 1234,
                ],
                [],
                true,
            ],
            // Withdraw and wdDate before appOutDate
            [
                [
                    'regDate' => Carbon::parse('2016-08-22'),
                    'appOutDate' => Carbon::parse('2016-08-23'),
                    'appInDate' => null,
                    'apprDate' => null,
                    'wdDate' => Carbon::parse('2016-08-22'),
                    'withdrawCodeId' => 1234,
                ],
                [
                    $this->getMessageData($this->messageTemplate, [
                        'id' => 'TEAMAPP_WD_DATE_BEFORE_APPOUT_DATE',
                        'reference.field' => 'wdDate',
                    ]),
                ],
                false,
            ],

            // Approved date OK
            [
                [
                    'regDate' => Carbon::parse('2016-08-22'),
                    'appOutDate' => Carbon::parse('2016-08-23'),
                    'appInDate' => Carbon::parse('2016-08-24'),
                    'apprDate' => Carbon::parse('2016-08-25'),
                ],
                [],
                true,
            ],
            // Approved and apprDate before regDate
            [
                [
                    'regDate' => Carbon::parse('2016-08-22'),
                    'appOutDate' => Carbon::parse('2016-08-23'),
                    'appInDate' => Carbon::parse('2016-08-24'),
                    'apprDate' => Carbon::parse('2016-08-21'),
                ],
                [
                    $this->getMessageData($this->messageTemplate, [
                        'id' => 'TEAMAPP_APPR_DATE_BEFORE_REG_DATE',
                        'reference.field' => 'apprDate',
                    ]),
                    $this->getMessageData($this->messageTemplate, [
                        'id' => 'TEAMAPP_APPR_DATE_BEFORE_APPIN_DATE',
                        'reference.field' => 'apprDate',
                    ]),
                    $this->getMessageData($this->messageTemplate, [
                        'id' => 'TEAMAPP_APPR_DATE_BEFORE_APPOUT_DATE',
                        'reference.field' => 'apprDate',
                    ]),
                ],
                false,
            ],
            // Approved and apprDate before appInDate
            [
                [
                    'regDate' => Carbon::parse('2016-08-22'),
                    'appOutDate' => Carbon::parse('2016-08-23'),
                    'appInDate' => Carbon::parse('2016-08-24'),
                    'apprDate' => Carbon::parse('2016-08-23'),
                ],
                [
                    $this->getMessageData($this->messageTemplate, [
                        'id' => 'TEAMAPP_APPR_DATE_BEFORE_APPIN_DATE',
                        'reference.field' => 'apprDate',
                    ]),
                ],
                false,
            ],
            // Approved and apprDate before appOutDate
            [
                [
                    'regDate' => Carbon::parse('2016-08-22'),
                    'appOutDate' => Carbon::parse('2016-08-23'),
                    'appInDate' => Carbon::parse('2016-08-24'),
                    'apprDate' => Carbon::parse('2016-08-22'),
                ],
                [
                    $this->getMessageData($this->messageTemplate, [
                        'id' => 'TEAMAPP_APPR_DATE_BEFORE_APPIN_DATE',
                        'reference.field' => 'apprDate',
                    ]),
                    $this->getMessageData($this->messageTemplate, [
                        'id' => 'TEAMAPP_APPR_DATE_BEFORE_APPOUT_DATE',
                        'reference.field' => 'apprDate',
                    ]),
                ],
                false,
            ],

            // AppIn date OK
            [
                [
                    'regDate' => Carbon::parse('2016-08-22'),
                    'appOutDate' => Carbon::parse('2016-08-25'),
                    'appInDate' => Carbon::parse('2016-08-29'),
                    'apprDate' => null,
                ],
                [],
                true,
            ],
            // AppIn and appInDate before regDate
            [
                [
                    'regDate' => Carbon::parse('2016-08-22'),
                    'appOutDate' => Carbon::parse('2016-08-25'),
                    'appInDate' => Carbon::parse('2016-08-21'),
                    'apprDate' => null,
                ],
                [
                    $this->getMessageData($this->messageTemplate, [
                        'id' => 'TEAMAPP_APPIN_DATE_BEFORE_REG_DATE',
                        'reference.field' => 'appInDate',
                    ]),
                    $this->getMessageData($this->messageTemplate, [
                        'id' => 'TEAMAPP_APPIN_DATE_BEFORE_APPOUT_DATE',
                        'reference.field' => 'appInDate',
                    ]),
                    $this->getMessageData($this->messageTemplate, [
                        'id' => 'TEAMAPP_APPR_LATE',
                        'reference.field' => 'apprDate',
                        'level' => 'warning',
                    ]),
                ],
                false,
            ],
            // AppIn and appInDate before appOutDate
            [
                [
                    'regDate' => Carbon::parse('2016-08-26'),
                    'appOutDate' => Carbon::parse('2016-08-29'),
                    'appInDate' => Carbon::parse('2016-08-26'),
                    'apprDate' => null,
                ],
                [
                    $this->getMessageData($this->messageTemplate, [
                        'id' => 'TEAMAPP_APPIN_DATE_BEFORE_APPOUT_DATE',
                        'reference.field' => 'appInDate',
                    ]),
                ],
                false,
            ],

            // AppOut date OK
            [
                [
                    'regDate' => Carbon::parse('2016-08-26'),
                    'appOutDate' => Carbon::parse('2016-08-29'),
                    'appInDate' => null,
                    'apprDate' => null,
                ],
                [],
                true,
            ],
            // AppOut and appOutDate before regDate
            [
                [
                    'regDate' => Carbon::parse('2016-08-31'),
                    'appOutDate' => Carbon::parse('2016-08-30'),
                    'appInDate' => null,
                    'apprDate' => null,
                ],
                [
                    $this->getMessageData($this->messageTemplate, [
                        'id' => 'TEAMAPP_APPOUT_DATE_BEFORE_REG_DATE',
                        'reference.field' => 'appOutDate',
                    ]),
                ],
                false,
            ],

            // AppOut within 3 days of regDate
            [
                [
                    'regDate' => Carbon::parse('2016-08-28'),
                    'appOutDate' => Carbon::parse('2016-08-31'),
                    'appInDate' => null,
                    'apprDate' => null,
                ],
                [],
                true,
            ],
            // AppOut not within 3 days of regDate (not complete)
            [
                [
                    'regDate' => Carbon::parse('2016-08-28'),
                    'appOutDate' => null,
                    'appInDate' => null,
                    'apprDate' => null,
                ],
                [
                    $this->getMessageData($this->messageTemplate, [
                        'id' => 'TEAMAPP_APPOUT_LATE',
                        'reference.field' => 'appOutDate',
                        'level' => 'warning',
                    ]),
                ],
                true,
            ],
            // AppOut not within 3 days of regDate (complete)
            [
                [
                    'regDate' => Carbon::parse('2016-08-27'),
                    'appOutDate' => Carbon::parse('2016-08-31'),
                    'appInDate' => null,
                    'apprDate' => null,
                ],
                [
                    $this->getMessageData($this->messageTemplate, [
                        'id' => 'TEAMAPP_APPOUT_LATE',
                        'reference.field' => 'appOutDate',
                        'level' => 'warning',
                    ]),
                ],
                true,
            ],
            // AppIn within 7 days of appOut
            [
                [
                    'regDate' => Carbon::parse('2016-08-22'),
                    'appOutDate' => Carbon::parse('2016-08-23'),
                    'appInDate' => Carbon::parse('2016-08-30'),
                    'apprDate' => null,
                ],
                [],
                true,
            ],
            // AppIn not within 7 days of appOut (not complete)
            [
                [
                    'regDate' => Carbon::parse('2016-08-22'),
                    'appOutDate' => Carbon::parse('2016-08-23'),
                    'appInDate' => null,
                    'apprDate' => null,
                ],
                [
                    $this->getMessageData($this->messageTemplate, [
                        'id' => 'TEAMAPP_APPIN_LATE',
                        'reference.field' => 'appInDate',
                        'level' => 'warning',
                    ]),
                ],
                true,
            ],
            // AppIn not within 7 days of appOut (complete)
            [
                [
                    'regDate' => Carbon::parse('2016-08-22'),
                    'appOutDate' => Carbon::parse('2016-08-23'),
                    'appInDate' => Carbon::parse('2016-08-31'),
                    'apprDate' => null,
                ],
                [
                    $this->getMessageData($this->messageTemplate, [
                        'id' => 'TEAMAPP_APPIN_LATE',
                        'reference.field' => 'appInDate',
                        'level' => 'warning',
                    ]),
                ],
                true,
            ],
            // Appr within 7 days of appIn
            [
                [
                    'regDate' => Carbon::parse('2016-08-22'),
                    'appOutDate' => Carbon::parse('2016-08-23'),
                    'appInDate' => Carbon::parse('2016-08-24'),
                    'apprDate' => Carbon::parse('2016-08-31'),
                ],
                [],
                true,
            ],
            // Appr not within 7 days of appIn (not complete)
            [
                [
                    'regDate' => Carbon::parse('2016-08-22'),
                    'appOutDate' => Carbon::parse('2016-08-23'),
                    'appInDate' => Carbon::parse('2016-08-24'),
                    'apprDate' => null,
                ],
                [
                    $this->getMessageData($this->messageTemplate, [
                        'id' => 'TEAMAPP_APPR_LATE',
                        'reference.field' => 'apprDate',
                        'level' => 'warning',
                    ]),
                ],
                true,
            ],
            // Appr not within 7 days of appIn (complete)
            [
                [
                    'regDate' => Carbon::parse('2016-08-22'),
                    'appOutDate' => Carbon::parse('2016-08-23'),
                    'appInDate' => Carbon::parse('2016-08-24'),
                    'apprDate' => Carbon::parse('2016-09-01'),
                ],
                [
                    $this->getMessageData($this->messageTemplate, [
                        'id' => 'TEAMAPP_APPR_LATE',
                        'reference.field' => 'apprDate',
                        'level' => 'warning',
                    ]),
                ],
                true,
            ],

            // RegDate in future
            [
                [
                    'regDate' => Carbon::parse('2016-09-09'),
                    'appOutDate' => null,
                    'appInDate' => null,
                    'apprDate' => null,
                ],
                [
                    $this->getMessageData($this->messageTemplate, [
                        'id' => 'TEAMAPP_REG_DATE_IN_FUTURE',
                        'reference.field' => 'regDate',
                    ]),
                ],
                false,
            ],
            // WdDate in future
            [
                [
                    'regDate' => Carbon::parse('2016-08-22'),
                    'appOutDate' => Carbon::parse('2016-08-23'),
                    'appInDate' => Carbon::parse('2016-08-24'),
                    'apprDate' => Carbon::parse('2016-08-25'),
                    'wdDate' => Carbon::parse('2016-09-09'),
                    'withdrawCodeId' => 1234,
                ],
                [
                    $this->getMessageData($this->messageTemplate, [
                        'id' => 'TEAMAPP_WD_DATE_IN_FUTURE',
                        'reference.field' => 'wdDate',
                    ]),
                ],
                false,
            ],
            // ApprDate in future
            [
                [
                    'regDate' => Carbon::parse('2016-08-22'),
                    'appOutDate' => Carbon::parse('2016-08-23'),
                    'appInDate' => Carbon::parse('2016-08-24'),
                    'apprDate' => Carbon::parse('2016-09-09'),
                ],
                [
                    $this->getMessageData($this->messageTemplate, [
                        'id' => 'TEAMAPP_APPR_DATE_IN_FUTURE',
                        'reference.field' => 'apprDate',
                    ]),
                ],
                false,
            ],
            // AppInDate in future
            [
                [
                    'regDate' => Carbon::parse('2016-08-22'),
                    'appOutDate' => Carbon::parse('2016-08-23'),
                    'appInDate' => Carbon::parse('2016-09-09'),
                    'apprDate' => null,
                ],
                [
                    $this->getMessageData($this->messageTemplate, [
                        'id' => 'TEAMAPP_APPIN_DATE_IN_FUTURE',
                        'reference.field' => 'appInDate',
                    ]),
                ],
                false,
            ],
            // AppOutDate in future
            [
                [
                    'regDate' => Carbon::parse('2016-08-22'),
                    'appOutDate' => Carbon::parse('2016-09-09'),
                    'appInDate' => null,
                    'apprDate' => null,
                ],
                [
                    $this->getMessageData($this->messageTemplate, [
                        'id' => 'TEAMAPP_APPOUT_DATE_IN_FUTURE',
                        'reference.field' => 'appOutDate',
                    ]),
                ],
                false,
            ],
        ];
    }

    /**
     * @dataProvider providerValidateTravel
     */
    public function testValidateTravel($data, $expectedMessages, $expectedResult)
    {
        $validator = $this->getValidatorMock($data);
        $result = $validator->run($this->getTeamApplication($data));

        $this->assertMessages($expectedMessages, $validator->getMessages());
        $this->assertEquals($expectedResult, $result);
    }

    public function providerValidateTravel()
    {
        return [
            // validateTravel Passes When Before Second Classroom
            [
                [
                    'travel' => null,
                    'room' => null,
                    'comment' => null,
                    'wdDate' => null,
                    'withdrawCodeId' => null,
                    'incomingQuarter' => 'next',
                    '__reportingDate' => Carbon::parse('2016-09-02'),
                ],
                [],
                true,
            ],
            // validateTravel Passes When Travel And Room Complete
            [
                [
                    'travel' => true,
                    'room' => true,
                    'comment' => null,
                    'wdDate' => null,
                    'withdrawCodeId' => null,
                    'incomingQuarter' => 'next',
                    '__reportingDate' => Carbon::parse('2016-10-07'),
                ],
                [],
                true,
            ],
            // validateTravel Passes When Comments Provided
            [
                [
                    'travel' => null,
                    'room' => null,
                    'comment' => 'Travel and rooming booked by May 4',
                    'wdDate' => null,
                    'withdrawCodeId' => null,
                    'incomingQuarter' => 'next',
                    '__reportingDate' => Carbon::parse('2016-10-07'),
                ],
                [
                    $this->getMessageData($this->messageTemplate, [
                        'id' => 'TEAMAPP_TRAVEL_COMMENT_REVIEW',
                        'reference.field' => 'comment',
                        'level' => 'warning',
                    ]),
                    $this->getMessageData($this->messageTemplate, [
                        'id' => 'TEAMAPP_ROOM_COMMENT_REVIEW',
                        'reference.field' => 'comment',
                        'level' => 'warning',
                    ]),
                ],
                true,
            ],
            // validateTravel Ignored When Wd Set
            [
                [
                    'travel' => null,
                    'room' => null,
                    'comment' => null,
                    'wdDate' => Carbon::parse('2016-08-26'),
                    'withdrawCodeId' => 1234,
                    'incomingQuarter' => 'next',
                    '__reportingDate' => Carbon::parse('2016-10-07'),
                ],
                [],
                true,
            ],
            // validateTravel Ignored When Incoming Weekend Equals Future
            [
                [
                    'travel' => null,
                    'room' => null,
                    'comment' => null,
                    'wdDate' => null,
                    'withdrawCodeId' => null,
                    'incomingQuarter' => 'future',
                    '__reportingDate' => Carbon::parse('2016-10-07'),
                ],
                [],
                true,
            ],
            // ValidateTravel Fails When Missing Travel
            [
                [
                    'travel' => null,
                    'room' => true,
                    'comment' => null,
                    'wdDate' => null,
                    'withdrawCodeId' => null,
                    'incomingQuarter' => 'next',
                    '__reportingDate' => Carbon::parse('2016-10-07'),
                ],
                [
                    $this->getMessageData($this->messageTemplate, [
                        'id' => 'TEAMAPP_TRAVEL_COMMENT_MISSING',
                        'reference.field' => 'comment',
                    ]),
                ],
                false,
            ],
            // ValidateTravel Fails When Missing Travel and comment is an empty string
            [
                [
                    'travel' => null,
                    'room' => true,
                    'comment' => '',
                    'wdDate' => null,
                    'withdrawCodeId' => null,
                    'incomingQuarter' => 'next',
                    '__reportingDate' => Carbon::parse('2016-10-07'),
                ],
                [
                    $this->getMessageData($this->messageTemplate, [
                        'id' => 'TEAMAPP_TRAVEL_COMMENT_MISSING',
                        'reference.field' => 'comment',
                    ]),
                ],
                false,
            ],
            // ValidateTravel Fails When Missing Room
            [
                [
                    'travel' => true,
                    'room' => null,
                    'comment' => null,
                    'wdDate' => null,
                    'withdrawCodeId' => null,
                    'incomingQuarter' => 'next',
                    '__reportingDate' => Carbon::parse('2016-10-07'),
                ],
                [
                    $this->getMessageData($this->messageTemplate, [
                        'id' => 'TEAMAPP_ROOM_COMMENT_MISSING',
                        'reference.field' => 'comment',
                    ]),
                ],
                false,
            ],
            // ValidateTravel Fails When Missing Room and comment is an empty string
            [
                [
                    'travel' => true,
                    'room' => null,
                    'comment' => '',
                    'wdDate' => null,
                    'withdrawCodeId' => null,
                    'incomingQuarter' => 'next',
                    '__reportingDate' => Carbon::parse('2016-10-07'),
                ],
                [
                    $this->getMessageData($this->messageTemplate, [
                        'id' => 'TEAMAPP_ROOM_COMMENT_MISSING',
                        'reference.field' => 'comment',
                    ]),
                ],
                false,
            ],
        ];
    }

    /**
     * @dataProvider providerValidateReviewer
     */
    public function testValidateReviewer($data, $expectedMessages, $expectedResult)
    {
        $validator = $this->getValidatorMock($data);
        $result = $validator->run($this->getTeamApplication($data));

        $this->assertMessages($expectedMessages, $validator->getMessages());
        $this->assertEquals($expectedResult, $result);
    }

    public function providerValidateReviewer()
    {
        return [
            // Team 1 and not a reviewer
            [
                [
                    'teamYear' => 1,
                    'isReviewer' => false,
                ],
                [],
                true,
            ],
            // Team 2 and not a reviewer
            [
                [
                    'teamYear' => 2,
                    'isReviewer' => false,
                ],
                [],
                true,
            ],
            // Team 1 and a reviewer
            [
                [
                    'teamYear' => 1,
                    'isReviewer' => true,
                ],
                [
                    $this->getMessageData($this->messageTemplate, [
                        'id' => 'TEAMAPP_REVIEWER_TEAM1',
                        'reference.field' => 'isReviewer',
                    ]),
                ],
                false,
            ],
            // Team 2 and not a reviewer
            [
                [
                    'teamYear' => 2,
                    'isReviewer' => true,
                ],
                [],
                true,
            ],
        ];
    }

    /**
     * @dataProvider providerValidateComment
     */
    public function testValidateComment($data, $expectedMessages, $expectedResult)
    {
        $validator = $this->getValidatorMock($data);
        $result = $validator->run($this->getTeamApplication($data));

        $this->assertMessages($expectedMessages, $validator->getMessages());
        $this->assertEquals($expectedResult, $result);
    }

    public function providerValidateComment()
    {
        return [
            // No comment
            [
                [
                    'comment' => null,
                ],
                [],
                true,
            ],
            // Short comment
            [
                [
                    'comment' => 'This is a great comment',
                ],
                [],
                true,
            ],
            // Long comment
            [
                [
                    'comment' => '01234567890123456789012345678901234567890123456789012345678901234567890123456789012345678901234567890123456789012345678901234567890123456789012345678901234567890123456789012345678901234567890123456789012345678901234567890123456789012345678901234567890123456789',
                ],
                [
                    $this->getMessageData($this->messageTemplate, [
                        'id' => 'GENERAL_COMMENT_TOO_LONG',
                        'reference.field' => 'comment',
                    ]),
                ],
                false,
            ],
        ];
    }

    /**
     * @dataProvider providerValidateWithdraw
     */
    public function testValidateWithdraw($data, $expectedMessages, $expectedResult)
    {
        $validator = $this->getValidatorMock($data);
        $result = $validator->run($this->getTeamApplication($data));

        $this->assertMessages($expectedMessages, $validator->getMessages());
        $this->assertEquals($expectedResult, $result);
    }

    public function providerValidateWithdraw()
    {
        return [
            // Not withdrawn
            [
                [
                    'withdrawCodeId' => null,
                ],
                [],
                true,
            ],
            // App only code
            [
                [
                    'wdDate' => Carbon::parse('2016-09-01'),
                    'withdrawCodeId' => 1,
                    '__withdrawCode' => [
                        'active' => true,
                        'context' => 'application',
                        'display' => 'Cool Code',
                    ],
                ],
                [],
                true,
            ],
            // Inactive code
            [
                [
                    'wdDate' => Carbon::parse('2016-09-01'),
                    'withdrawCodeId' => 2,
                    '__withdrawCode' => [
                        'active' => false,
                        'context' => 'application',
                        'display' => 'Another Cool Code',
                    ],
                ],
                [
                    $this->getMessageData($this->messageTemplate, [
                        'id' => 'TEAMAPP_WD_CODE_INACTIVE',
                        'reference.field' => 'withdrawCodeId',
                    ]),
                ],
                false,
            ],
            // Invalid code
            [
                [
                    'wdDate' => Carbon::parse('2016-09-01'),
                    'withdrawCodeId' => 99,
                    '__withdrawCode' => [],
                ],
                [
                    $this->getMessageData($this->messageTemplate, [
                        'id' => 'TEAMAPP_WD_CODE_UNKNOWN',
                        'reference.field' => 'withdrawCodeId',
                    ]),
                ],
                false,
            ],
        ];
    }

    /**
     * @dataProvider providerValidateEmail
     */
    public function testValidateEmail($data, $bouncedEmails, $expectedMessages, $expectedResult)
    {
        $this->setSetting('bouncedEmails', $bouncedEmails);

        $validator = $this->getValidatorMock($data);
        $result = $validator->run($this->getTeamApplication($data));

        $this->assertMessages($expectedMessages, $validator->getMessages());
        $this->assertEquals($expectedResult, $result);
    }

    public function providerValidateEmail()
    {
        return [
            // No bounced emails
            [
                [],
                '',
                [],
                true,
            ],
            // Has bounced emails but doesn't match
            [
                [],
                'some-other@tmlpstats.com',
                [],
                true,
            ],
            // Has multple bounced emails but doesn't match
            [
                [],
                'some-other@tmlpstats.com,and-another@tmlpstats.com,and-finally@tmlpstats.com',
                [],
                true,
            ],
            // Matches bounced email
            [
                [
                    'email' => 'a-match@tmlpstats.com',
                ],
                'some-other@tmlpstats.com,a-match@tmlpstats.com',
                [
                    $this->getMessageData($this->messageTemplate, [
                        'id' => 'TEAMAPP_BOUNCED_EMAIL',
                        'level' => 'warning',
                        'reference.field' => 'email',
                    ]),
                ],
                true,
            ],
        ];
    }

    /**
     * @dataProvider providerIsStartingNextQuarter
     */
    public function testIsStartingNextQuarter($data, $expected)
    {
        $validator = $this->getValidatorMock($data);
        $result = $validator->isStartingNextQuarter($this->getTeamApplication($data));

        $this->assertEquals($expected, $result);
    }

    public function providerIsStartingNextQuarter()
    {
        return [
            // Is Starting Next Quarter
            [
                [
                    'incomingQuarter' => 'next',
                ],
                true,
            ],
            // Not Starting Next Quarter
            [
                [
                    'incomingQuarter' => 'future',
                ],
                false,
            ],
        ];
    }

    //
    // Helpers
    //

    public function getValidatorMock($data, $methods = [])
    {
        $methods = array_merge(['getWithdrawCode'], $methods);

        $mock = $this->getObjectMock($methods);

        if (isset($data['withdrawCodeId'])) {
            // If __withdrawCode isn't set at all, provide a reasonable default
            if (!isset($data['__withdrawCode'])) {
                $data['__withdrawCode'] = [
                    'active' => true,
                    'context' => 'application',
                    'display' => 'Cool Code',
                ];
            }

            // If __withdrawCode is empty, we'll pretend it doesn't exist
            $code = null;
            if (isset($data['__withdrawCode']['active'])) {
                $code = new \stdClass;

                $code->active = $data['__withdrawCode']['active'];
                $code->context = $data['__withdrawCode']['context'];
                $code->display = $data['__withdrawCode']['display'];
            }

            $mock->method('getWithdrawCode')
                 ->with($data['withdrawCodeId'])
                 ->willReturn($code);
        }

        return $mock;
    }

    public function getTeamApplication($data)
    {
        if (isset($data['__reportingDate'])) {
            $this->statsReport->reportingDate = $data['__reportingDate'];
            unset($data['__reportingDate']);
        }

        if (isset($data['incomingQuarter'])) {
            if ($data['incomingQuarter'] == 'next') {
                $data['incomingQuarter'] = $this->nextQuarter->id;
            } else if ($data['incomingQuarter'] == 'future') {
                $data['incomingQuarter'] = $this->futureQuarter->id;
            }
        }

        $data = array_merge($this->dataTemplate, $data);

        return TeamApplication::fromArray($data);
    }
}
