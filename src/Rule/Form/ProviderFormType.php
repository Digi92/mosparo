<?php

namespace Mosparo\Rule\Form;

use Mosparo\Util\ChoicesUtil;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;

class ProviderFormType extends AbstractRuleTypeFormType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $ruleType = $options['rule_type'];
        if ($ruleType === null) {
            return;
        }

        $choices = ChoicesUtil::buildChoices($ruleType->getSubtypes());
        $builder
            ->add('type', ChoiceType::class, [
                'label' => 'rule.form.items.type',
                'choices' => $choices,
                'attr' => ['readonly' => (count($choices) === 1), 'class' => 'form-select rule-item-type']
            ])
            ->add('value', TextType::class, [
                'label' => 'rule.type.provider.label',
                'attr' => ['placeholder' => 'rule.type.provider.placeholder', 'class' => 'rule-item-value']
            ])
            ->add('spamRatingFactor', NumberType::class, [
                'label' => 'rule.form.items.rating',
                'required' => false,
                'html5' => true,
                'scale' => 1,
                'attr' => [
                    'placeholder' => '1.0',
                    'class' => 'rule-item-rating',
                    'min' => 0.1,
                    'step' => 'any',
                ]
            ])
        ;
    }
}
